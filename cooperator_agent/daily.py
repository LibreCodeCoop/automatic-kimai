"""Daily per-cooperator review or explicitly opted-in nonbillable preview sync.

Usage:
  python3 -m cooperator_agent.daily --profile PATH --env-file PATH
  python3 -m cooperator_agent.daily --profile PATH --env-file PATH --apply

Systemd timer deliberately runs without --apply until real worker testing.
Never guesses external-client contracts or automatically approves payroll hours.
"""
from __future__ import annotations
import argparse
import datetime as dt
import json,os
from dataclasses import replace
from pathlib import Path

from kimai_client import KimaiClient,load_dotenv,minimal_catalog,save_private_json
from .profile import Profile
from .segment import segment
from .reconcile import Ledger,plan_one,execute,remote_day_list,validate_catalog,reconcile_pending
from .agent_runs import AgentRuns,overlaps as overlapping_agent_seconds
from .secrets import read_credentials
from .providers import read_sources
from .lock import acquire as acquire_lock,release as release_lock
from .coverage import (uncovered_activity,local_action_anchors,missing_expected_workday,
                       scheduled_event_gaps)
from .reviewable import reviewable_intervals
from .attendance import read_plan_records

def _get_events(profile:Profile,date:dt.date,now:dt.datetime):
    file=profile.cache_dir/("aw_sanitized_"+date.isoformat()+".json")
    if not file.exists():
        return [],"missing_activitywatch_file"
    if file.is_symlink():raise ValueError("Symlinked evidence file forbidden")
    if os.name!="nt" and file.stat().st_mode&0o077:
        raise ValueError("Private activity cache has insecure permissions")
    obj=json.loads(file.read_text(encoding="utf-8"))
    if obj.get("local_date")!=date.isoformat():
        raise ValueError("ActivityWatch day mismatch")
    events=obj.get("events",[])
    # Old collector versions implicitly treated missing AFK status as
    # "not idle". Such evidence is not safe for an unattended writer.
    if any(x.get("idle_status_known") not in (True,False) for x in events):
        return [],"legacy_activitywatch_without_afk_coverage"
    if date==now.date():
        # Capturing foreground may have silently stopped even when the systemd
        # timer is active. A stale current-day cache must fail closed.
        freshness=now.timestamp()-file.stat().st_mtime
        if freshness>profile.capture_freshness_minutes*60 or freshness< -180:
            return [],"stale_activitywatch_capture"
    return events,None

def _calendar(profile,*,enabled:bool=True):
    # Opted-out portable profiles must not use cached calendar evidence.
    if not enabled:
        return []
    file=profile.cache_dir/"calendar_work_context.json"
    if not file.is_file():return []
    if file.is_symlink():raise ValueError("Symlinked calendar cache forbidden")
    return json.loads(file.read_text(encoding="utf-8")).get("events",[])

def collect_decisions(profile:Profile,*,now:dt.datetime,remote,ledger:Ledger,user_id:int,
                      agent_intervals=(),calendar_enabled:bool|None=None):
    if now.tzinfo is None:raise ValueError("Aware now required")
    now=now.astimezone(profile.zone)
    if calendar_enabled is None:
        # Legacy pilot has a separately authorized Calendar systemd collector.
        calendar_enabled=profile.collector_backend=="legacy"
    calendar=_calendar(profile,enabled=calendar_enabled)
    anchors=local_action_anchors(profile)
    days=[now.date()-dt.timedelta(days=i) for i in range(profile.lookback_days)]
    results=[]
    for day in sorted(days):
        events,warning=_get_events(profile,day,now)
        if warning:
            results.append({"day":day.isoformat(),"warning":warning,
                            "sessions":[],"coverage_gaps":[],"review_intervals":[],
                            "calendar_gap_alerts":scheduled_event_gaps(
                                day.isoformat(),calendar,remote,profile,now),
                            "missing_workday_alerts":missing_expected_workday(
                                profile,day.isoformat(),remote,now)})
            continue
        windows=segment(day.isoformat(),events,calendar,profile,now)
        sections=[]
        for window in windows:
            decision=plan_one(window,profile,remote,ledger.get(window.id),user_id=user_id)
            # Agent runtime can include simulated keyboard/mouse activity.
            # Without independently attested human interaction, never count
            # a window overlapping autonomous execution as auto-publishable.
            if (decision.action in ("post","patch") and
                overlapping_agent_seconds(window,agent_intervals)>=60):
                decision=type(decision)("review",window,decision.remote_id,
                                       "agent_execution_overlap_needs_human_attribution")
            # Require at least some direct work evidence before automatic writes.
            # ChatGPT alone is never a publication signal (shared account).
            if (decision.action in ("post","patch") and
                (window.work_seconds<profile.auto_min_work_minutes*60 or
                (window.end-window.start).total_seconds()<profile.min_session_minutes*60 or
                (window.end-window.start).total_seconds()>profile.auto_max_session_hours*3600 or
                window.uncovered_seconds > (window.end-window.start).total_seconds()*
                profile.auto_max_unknown_fraction)):
                decision=type(decision)("review",window,decision.remote_id,
                                        "insufficient_independent_desktop_evidence")
            sections.append((decision,window))
        gaps=uncovered_activity(day.isoformat(),events,remote,profile,now,
                                calendar,anchors)
        review=reviewable_intervals(day.isoformat(),events,remote,profile,now,
                                    calendar,anchors)
        results.append({"day":day.isoformat(),"warning":None,
                        "sessions":sections,"coverage_gaps":gaps,
                        "review_intervals":review,
                        "calendar_gap_alerts":scheduled_event_gaps(
                            day.isoformat(),calendar,remote,profile,now),
                        "missing_workday_alerts":missing_expected_workday(
                            profile,day.isoformat(),remote,now)})
    return results



def annotate_overnight_reviews(days:list[dict])->int:
    """Flag a midnight-adjacent review, without changing eligibility."""
    boundaries=set()
    count=0
    for group in days:
        for item in group["sessions"]:
            start=dt.datetime.fromisoformat(item["begin"])
            if (item["action"]=="review" and start in boundaries
                and start.hour==0 and start.minute==0):
                item["possible_overnight_continuation"]=True
                count+=1
            end=dt.datetime.fromisoformat(item["end"])
            if end.date()!=start.date() and end.hour==0 and end.minute==0:
                boundaries.add(end)
    return count


def render_private_html(report:dict,state_dir:Path):
    """Local review page; all text escaped, no JavaScript or network calls."""
    import html
    rows=[]
    labels={"post":"Nova sessão para revisar","patch":"Extender o mesmo ID",
            "unchanged":"Já conciliado","review":"Exige revisão",
            "hold":"Em andamento"}
    for day in report["days"]:
        if day["warning"]:
            rows.append("<tr><td>"+html.escape(day["day"])+"</td><td colspan='6'>"+
                        html.escape(day["warning"])+"</td></tr>")
        for item in day["sessions"]:
            start=dt.datetime.fromisoformat(item["begin"]).strftime("%H:%M")
            end=dt.datetime.fromisoformat(item["end"]).strftime("%H:%M")
            label=labels.get(item["action"],item["action"])
            if item.get("possible_overnight_continuation"):
                label+=" — possível continuação após meia-noite (revisar)"
            source=(f"{round(item['work_seconds']/60)}min de fonte profissional autorizada, "+
                    f"{round(item['chat_unknown_seconds']/60)}min de conversa IA incerta; "+
                    f"{round(item['uncovered_seconds']/60)}min não observado")
            ident=str(item.get("remote_id") or "novo")
            rows.append("<tr><td>"+html.escape(day["day"])+"</td><td>"+
                        html.escape(start+"–"+end)+"</td><td>"+
                        html.escape(label)+"</td><td>"+
                        html.escape(ident)+"</td><td>"+
                        html.escape(source)+"</td><td>"+
                        html.escape(str(round(item.get("agent_overlap_seconds",0)/60))+" min")+"</td><td>"+
                        html.escape(item["reason"])+"</td></tr>")
    gap_rows=[]
    for group in report["days"]:
        for gap in group.get("coverage_gaps",[]):
            a=dt.datetime.fromisoformat(gap["start"]).strftime("%d/%m %H:%M")
            b=dt.datetime.fromisoformat(gap["end"]).strftime("%d/%m %H:%M")
            gap_rows.append("<tr><td>"+html.escape(a+"–"+b)+"</td><td>"+
                            str(gap["observed_nonafk_minutes"])+"</td><td>"+
                            str(gap["approved_work_context_minutes"])+"</td><td>"+
                            str(gap["unverified_chat_minutes"])+"</td><td>"+
                            str(gap.get("outgoing_external_actions",0))+"</td><td>"+
                            html.escape(gap["reason"])+"</td></tr>")
    review_rows=[]
    for group in report["days"]:
        for entry in group.get("review_intervals",[]):
            start=dt.datetime.fromisoformat(entry["start"]).strftime("%d/%m %H:%M")
            end=dt.datetime.fromisoformat(entry["end"]).strftime("%H:%M")
            review_rows.append("<tr><td>"+html.escape(start+"–"+end)+"</td><td>"+
                str(entry["observed_nonafk_minutes"])+"</td><td>"+
                str(entry["unverified_chat_minutes"])+"</td><td>"+
                str(entry["approved_work_context_minutes"])+"</td><td>"+
                str(entry["other_active_minutes"])+"</td><td>"+
                str(entry["outgoing_external_actions"])+"</td></tr>")
    missing_rows=[]
    for group in report["days"]:
        for alert in group.get("missing_workday_alerts",[]):
            missing_rows.append("<li>"+html.escape(alert["day"])+
                ": nenhum apontamento no Kimai; revisar possível trabalho fora do computador. "
                "Nenhuma duração foi presumida.</li>")
    calendar_rows=[]
    calendar_labels={
        "work_candidate":"Possível compromisso profissional",
        "work_event_latinoware":"Evento profissional a confirmar",
        "possible_meeting_unclassified":"Reunião a verificar",
        "unclassified_not_work_proof":"Compromisso não classificado",
    }
    for group in report["days"]:
        for alert in group.get("calendar_gap_alerts",[]):
            start=dt.datetime.fromisoformat(alert["start"]).strftime("%d/%m %H:%M")
            end=dt.datetime.fromisoformat(alert["end"]).strftime("%H:%M")
            intervals=", ".join(
                dt.datetime.fromisoformat(x["start"]).strftime("%H:%M")+"–"+
                dt.datetime.fromisoformat(x["end"]).strftime("%H:%M")
                for x in alert["uncovered_intervals"])
            calendar_rows.append(
                "<tr><td>"+html.escape(start+"–"+end)+"</td><td>"+
                html.escape(calendar_labels.get(alert["category"],"A verificar"))+
                "</td><td>"+html.escape(str(alert["uncovered_schedule_minutes"]))+
                "</td><td>"+html.escape(intervals)+
                "</td><td>Não; confirmar presença e horários reais</td></tr>")
    onsite_rows=[]
    for event in report.get("offscreen_attendance",[]):
        ranges=", ".join(a+"–"+b for a,b in event["scheduled_segments"])
        onsite_rows.append("<tr><td>"+html.escape(event["date"])+
            "</td><td>"+html.escape(event["label"])+
            "</td><td>"+html.escape(ranges)+
            "</td><td>"+("Sim, previsão de agenda" if event["bounds_estimated"]
                          else "Não, informados pelo cooperado")+
            "</td><td>Não</td></tr>")
    source=html.escape(report["profile_id"])
    created=html.escape(report["created_at"])
    telemetry=html.escape(report.get("agent_telemetry_status","not_connected"))
    agent_rows=[]
    for task in report.get("agent_execution_intervals",[]):
        a=dt.datetime.fromisoformat(task["start"]).strftime("%d/%m %H:%M")
        b=dt.datetime.fromisoformat(task["end"]).strftime("%d/%m %H:%M")
        agent_rows.append("<tr><td>"+html.escape(task["source"])+
                          "</td><td>"+html.escape(a+"–"+b)+
                          "</td><td>"+html.escape(task["state"])+
                          "</td><td>Não</td></tr>")
    doc=("""<!doctype html><html lang="pt-BR"><meta charset="utf-8">
<title>Automatic Kimai — Plano de reconciliação</title>
<style>body{font:15px/1.5 system-ui;max-width:1350px;margin:30px auto;padding:0 20px}
table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:10px;
border-bottom:1px solid #999;vertical-align:top}th{background:#ddd;color:#222}
small{color:inherit}@media(prefers-color-scheme:dark){body{background:#171b22;color:#eee}th{background:#333;color:#eee}}</style>
<h1>Automatic Kimai — Revisão diária</h1>"""+
"<p>Perfil: "+source+" | Atualizado em: "+created+
"</p><p>Telemetria de execução da IA: "+telemetry+
". A ausência de eventos NÃO comprova ausência de agentes. Interações pelo celular "+
"não são detectadas pelo ActivityWatch do computador.</p>"+
"""<p><strong>Este relatório NÃO comprova horas humanas, contrato ou remuneração.</strong>
As janelas são inferências de atividade de tela, que podem conter leitura, silêncio
e atividade de ferramentas automatizadas. O modo padrão é somente revisão.</p>
<table><thead><tr><th>Data</th><th>Horário</th><th>Decisão</th><th>Kimai ID</th>
<th>Evidência contextual</th><th>IA na janela</th><th>Motivo</th></tr></thead><tbody>"""+
"".join(rows)+"""</tbody></table>
<h2>Atividade observada sem apontamento no Kimai</h2>
<p>Estas lacunas NÃO são horas trabalhadas certificadas. Incluem
janelas com evidência parcial, apps não atribuídos e pausas;
exigem revisão humana e não geram publicações automáticas.</p>
<table><thead><tr><th>Intervalo</th><th>Minutos ativos observados</th>
<th>Minutos de fontes aprovadas</th><th>Minutos em IA sem autoria comprovada</th>
<th>Mensagens próprias externas</th><th>Motivo</th></tr></thead><tbody>"""+
"".join(gap_rows)+"""</tbody></table>
<h2>Intervalos detalhados para confirmação do cooperado</h2>
<p>A atividade do computador não comprova horas humanas. Se você realizou
trabalho nesses períodos, use o botão "Revisar horas de trabalho não
registradas" do aplicativo, ajuste as horas reais e desconte pausas.
A submissão requer sua confirmação explícita e não aprova remuneração.</p>
<table><thead><tr><th>Data e intervalo</th><th>Min. observados no PC</th>
<th>Min. de IA sem atribuição</th><th>Min. fontes autorizadas</th>
<th>Min. outras janelas</th><th>Ações externas próprias</th></tr></thead><tbody>"""+
"".join(review_rows)+"""</tbody></table>
<h2>Compromissos agendados sem cobertura completa no Kimai</h2>
<p>O calendário indica apenas horários PREVISTOS, não prova que houve trabalho,
presença ou duração efetiva. Mesmo em dias com outros apontamentos, mostramos
compromissos sem cobertura, incluindo períodos fora do computador. Títulos
e participantes não são guardados neste relatório. Para registrar algo,
confirme presença, trabalho efetivo, horas e pausas no formulário
"Registrar trabalho presencial / fora do computador". Nada é lançado
automaticamente a partir do calendário.</p>
<table><thead><tr><th>Agenda prevista</th><th>Categoria</th>
<th>Minutos de agenda sem cobertura</th><th>Intervalos sem apontamento</th>
<th>Comparecimento verificado?</th></tr></thead><tbody>"""+
"".join(calendar_rows)+"""</tbody></table>
<h2>Trabalho presencial ou fora do computador</h2>
<p>Presença declarada pelo cooperado, independente do ActivityWatch.
Horários provenientes de uma agenda anterior continuam estimados e precisam
de conferência individual; a agenda sozinha não comprova presença.</p>
<table><thead><tr><th>Data</th><th>Atividade</th><th>Períodos após pausas</th>
<th>Horários estimados?</th><th>Remuneração aprovada?</th></tr></thead><tbody>"""+
"".join(onsite_rows)+"""</tbody></table>
<h2>Dias úteis concluídos sem apontamento</h2>
<p>Alerta opcional para dias úteis de trabalho habituais. Não indica
presença comprovada nem cria horas automaticamente.</p><ul>"""+
"".join(missing_rows)+"""</ul>
<h2>Execuções de agentes (telemetria explícita)</h2>
<p>Tempo de processamento de IA nunca é convertido em horas humanas.</p>
<table><thead><tr><th>Origem</th><th>Intervalo</th><th>Estado</th>
<th>Contar como trabalho humano?</th></tr></thead><tbody>"""+
"".join(agent_rows)+"""</tbody></table>
<p><small>Comentários de auditoria ficam neste relatório e no histórico privado,
nunca no campo descrição dos timesheets.</small></p></html>""")
    path=state_dir/"latest_daily_plan.html"
    if path.is_symlink():raise ValueError("Refusing symlinked daily report")
    fd=os.open(path,os.O_CREAT|os.O_TRUNC|os.O_WRONLY,0o600)
    with os.fdopen(fd,"w",encoding="utf-8") as f:f.write(doc)
    path.chmod(0o600)
    return path

def run(*,profile_path:Path,env_path:Path,apply:bool=False,now=None,
        lookback_days_override:int|None=None):
    profile=Profile.read(profile_path)
    if lookback_days_override is not None:
        profile=replace(profile,lookback_days=lookback_days_override)
        profile.validate()
    zone=profile.zone
    now=(now or dt.datetime.now(zone)).astimezone(zone)
    if now.tzinfo is None:raise ValueError("Aware current date required")
    secrets=load_dotenv(Path(env_path).expanduser())
    secrets.update(read_credentials(Path(env_path).expanduser(),profile_id=profile.profile_id))
    client=KimaiClient(secrets["KIMAI_BASE_URL"],secrets["KIMAI_API_TOKEN"])
    me,_=client.get("/users/me")
    worker_id=me.get("id")
    if type(worker_id) is not int or worker_id<1:
        raise ValueError("Unknown Kimai user")
    if me.get("timezone")!=profile.timezone:
        raise ValueError("Kimai timezone differs from worker profile")
    if apply:
        if (profile.sync_mode!="preview_nonbillable" or
            profile.allow_auto_nonbillable_preview is not True or
            secrets.get("KIMAI_WRITE_ENABLED")!="true" or
            secrets.get("KIMAI_AUTO_PREVIEW_OPT_IN")!="true"):
            raise ValueError("Unattended writes not explicitly authorized for this worker")
    validate_catalog(client,profile)
    profile.state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    if profile.state_dir.is_symlink() or (os.name!="nt" and profile.state_dir.stat().st_mode&0o077):
        raise ValueError("State directory must be private, chmod 700")
    lock=acquire_lock(profile.state_dir/"daily.lock")
    try:
        ledger=Ledger(profile.state_dir/"ledger.sqlite3",user_id=worker_id,profile_id=profile.profile_id)
        try:
            days=[now.date()-dt.timedelta(days=i) for i in range(profile.lookback_days)]
            reconciliation=reconcile_pending(client,profile,worker_id,ledger,days)
            remote=remote_day_list(client,worker_id,days,zone)
            agent_file=profile.state_dir/"agent_runs.sqlite3"
            agent_store=AgentRuns(agent_file,profile.profile_id,create=False)
            try:
                agent_intervals=agent_store.intervals(
                    dt.datetime.combine(min(days),dt.time.min,tzinfo=zone),now)
            finally:
                agent_store.close()
            calendar_enabled=(profile.collector_backend=="legacy"
                              or read_sources(profile_path).get("nextcloud_calendar",False))
            groups=collect_decisions(profile,now=now,remote=remote,ledger=ledger,
                                     user_id=worker_id,agent_intervals=agent_intervals,
                                     calendar_enabled=calendar_enabled)
            report={"created_at":now.isoformat(),"profile_id":profile.profile_id,
                    "pending_reconciliation":reconciliation,
                    "user_id":worker_id,"timezone":profile.timezone,
                    "mode":profile.sync_mode,"writes_enabled_for_this_run":apply,
                    "agent_telemetry_status":("explicit_metadata_only" if agent_file.exists()
                                               else "no_integration_connected"),
                    "agent_execution_intervals":[{
                        "source":r["source"],"start":r["start"].isoformat(),
                        "end":r["end"].isoformat(),"state":r["state"],
                        "execution_seconds":r["execution_seconds"],
                        "counts_as_human_work":False,
                    } for r in agent_intervals],
                    "billing_approved":False,"payroll_approved":False,"days":[],
                    "calendar_review_enabled":calendar_enabled,
                    "offscreen_attendance":read_plan_records(
                        profile,[x.isoformat() for x in days])}
            if apply and reconciliation["unresolved"]:
                raise RuntimeError("Unresolved Kimai outbox: no automatic writes until reconciled")
            for group in groups:
                mapped={"day":group["day"],"warning":group["warning"],
                        "coverage_gaps":group.get("coverage_gaps",[]),
                        "review_intervals":group.get("review_intervals",[]),
                        "missing_workday_alerts":group.get("missing_workday_alerts",[]),
                        "calendar_gap_alerts":group.get("calendar_gap_alerts",[]),
                        "sessions":[]}
                for decision,window in group["sessions"]:
                    action=decision.action
                    result={"status":"dry_run"}
                    if apply and action in ("post","patch"):
                        # Do not post partial current-day snippets with zero
                        # foreground work and do not ever change contract.
                        result=execute(decision,client=client,profile=profile,
                                       user_id=worker_id,ledger=ledger)
                        # The next step checks the live server for further
                        # decisions, rather than reuse a stale preflight.
                    mapped["sessions"].append({**window.to_json(),**decision.to_json(),
                                               "agent_overlap_seconds":overlapping_agent_seconds(
                                                   window,agent_intervals),
                                               "result":result})
                report["days"].append(mapped)
            if apply and any(
                item["result"].get("status")=="reconciled"
                for group in report["days"] for item in group["sessions"]
            ):
                # Recompute gap warnings against the latest GET AFTER a POST/PATCH,
                # so the user does not see a newly filled gap as still missing.
                fresh_remote=remote_day_list(client,worker_id,days,zone)
                calendar=_calendar(profile,enabled=calendar_enabled)
                anchors=local_action_anchors(profile)
                for day_group in report["days"]:
                    day=dt.date.fromisoformat(day_group["day"])
                    events,warning=_get_events(profile,day,now)
                    if not warning:
                        day_group["coverage_gaps"]=uncovered_activity(
                            day_group["day"],events,fresh_remote,profile,now,
                            calendar,anchors)
                        day_group["review_intervals"]=reviewable_intervals(
                            day_group["day"],events,fresh_remote,profile,now,
                            calendar,anchors)
                    day_group["missing_workday_alerts"]=missing_expected_workday(
                        profile,day_group["day"],fresh_remote,now)
                    day_group["calendar_gap_alerts"]=scheduled_event_gaps(
                        day_group["day"],calendar,fresh_remote,profile,now)
            # Report-only continuity clue; never increases work or writes.
            report["overnight_review_count"]=annotate_overnight_reviews(
                report["days"])
            report["coverage_gap_count"]=sum(
                len(item["coverage_gaps"]) for item in report["days"])
            report["manual_review_interval_count"]=sum(
                len(item["review_intervals"]) for item in report["days"])
            report["missing_workday_count"]=sum(
                len(item["missing_workday_alerts"]) for item in report["days"])
            report["calendar_gap_count"]=sum(
                len(item["calendar_gap_alerts"]) for item in report["days"])
            save_private_json(profile.state_dir/"latest_daily_plan.json",report)
            render_private_html(report,profile.state_dir)
            counts={}
            for day in report["days"]:
                for s in day["sessions"]:
                    counts[s["action"]]=counts.get(s["action"],0)+1
            print("WORKER_DAILY_AGENT",now.date().isoformat(),"PROFILE",profile.profile_id,
                  "MODE",profile.sync_mode,"APPLY",apply,"ACTION_COUNTS",counts,
                  "WRITES",sum(s["result"].get("status")=="reconciled" for d in report["days"] for s in d["sessions"]),
                  "GAP_ALERTS",report["coverage_gap_count"],
                  "MANUAL_REVIEW",report["manual_review_interval_count"],
                  "CALENDAR_GAPS",report["calendar_gap_count"],
                  "OVERNIGHT_REVIEWS",report["overnight_review_count"])
            return report
        finally:
            ledger.close()
    finally:
        release_lock(lock)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--profile",type=Path,required=True)
    parser.add_argument("--env-file",type=Path,required=True)
    parser.add_argument("--apply",action="store_true")
    args=parser.parse_args()
    run(profile_path=args.profile,env_path=args.env_file,apply=args.apply)

if __name__=="__main__":
    main()
