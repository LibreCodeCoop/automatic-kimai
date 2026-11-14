"""Local graphical review of work periods the automatic classifier could not verify.

The person must enter/confirm actual hours, excluding breaks. Nothing is
written simply by opening or selecting a suggested interval.
"""
from __future__ import annotations
import datetime as dt
import json
import tkinter as tk
from tkinter import ttk,messagebox
from pathlib import Path

from .profile import Profile
from .manual_review import bounds,submit


def open_review(parent,profile_path:Path,env_path:Path):
    profile=Profile.read(profile_path)
    report_path=profile.state_dir/"latest_daily_plan.json"
    report=json.loads(report_path.read_text(encoding="utf-8"))
    if report.get("profile_id")!=profile.profile_id:
        raise ValueError("Review report belongs to another worker")
    intervals=[item for day in report.get("days",[])
               for item in day.get("review_intervals",[])]
    dialog=tk.Toplevel(parent)
    dialog.title("Revisar horas de trabalho não registradas")
    dialog.geometry("810x650")
    ttk.Label(dialog,text="Períodos abaixo indicam atividade observada, "
              "NÃO horas efetivamente trabalhadas. Ajuste início e fim à "
              "atividade HUMANA realizada, descontando pausas e a execução "
              "autônoma da IA. Também pode informar trabalho pelo celular.",
              wraplength=765).pack(padx=14,pady=(14,9))
    fields=("day","interval","observed","ai","work","talk")
    tree=ttk.Treeview(dialog,columns=fields,show="headings",height=8)
    for key,title,width in [
        ("day","Data",90),("interval","Intervalo",130),
        ("observed","Atividade PC",110),("ai","IA incerta",110),
        ("work","Fontes autorizadas",145),("talk","Ações Talk",88)]:
        tree.heading(key,text=title)
        tree.column(key,width=width)
    tree.pack(fill="both",expand=True,padx=13,pady=8)
    for i,entry in enumerate(intervals):
        a=dt.datetime.fromisoformat(entry["start"])
        b=dt.datetime.fromisoformat(entry["end"])
        tree.insert("",tk.END,iid=str(i),values=[
            entry["day"],a.strftime("%H:%M")+"–"+b.strftime("%H:%M"),
            str(entry["observed_nonafk_minutes"])+" min",
            str(entry["unverified_chat_minutes"])+" min",
            str(entry["approved_work_context_minutes"])+" min",
            entry.get("outgoing_external_actions",0),
        ])
    if not intervals:
        ttk.Label(dialog,text="Sem candidatos; é possível informar "
                  "trabalho realizado fora do computador.",
                  wraplength=760).pack(padx=14,pady=5)
    fields_frame=ttk.Frame(dialog,padding=12)
    fields_frame.pack(fill="x")
    datestr=tk.StringVar(value=dt.datetime.now(profile.zone).date().isoformat())
    begin=tk.StringVar()
    end=tk.StringVar()
    task=tk.StringVar(value="desenvolvimento e infraestrutura")
    confirmed=tk.BooleanVar(value=False)
    fields_frame.columnconfigure(1,weight=1)
    for row,(label,var,width) in enumerate((
        ("Dia (AAAA-MM-DD)",datestr,16),
        ("Início real (HH:MM)",begin,12),
        ("Fim real (HH:MM)",end,12),
        ("Atividade realizada",task,50))):
        ttk.Label(fields_frame,text=label).grid(row=row,column=0,sticky="w",pady=6)
        ttk.Entry(fields_frame,textvariable=var,width=width).grid(
            row=row,column=1,sticky="ew",padx=10,pady=6)
    def selection_changed(_=None):
        selected=tree.selection()
        if not selected:return
        entry=intervals[int(selected[0])]
        datestr.set(entry["day"])
        begin.set(dt.datetime.fromisoformat(entry["start"]).strftime("%H:%M"))
        end.set(dt.datetime.fromisoformat(entry["end"]).strftime("%H:%M"))
        confirmed.set(False)
    tree.bind("<<TreeviewSelect>>",selection_changed)
    ttk.Checkbutton(dialog,variable=confirmed,
        text="Confirmo que TRABALHEI pessoalmente nesse período, sem pausas "
             "nem processamento autônomo da IA.").pack(anchor="w",padx=20,pady=9)
    ttk.Label(dialog,text="Cada confirmação gera apenas um apontamento "
              "INTERNO não faturável, sujeito a validação posterior. "
              "Não serão sobrescritos registros existentes; sessões superiores "
              "a "+str(profile.max_session_hours)+" horas devem ser divididas.",
              wraplength=760).pack(anchor="w",padx=20,pady=5)
    def submit_clicked():
        if not confirmed.get():
            messagebox.showwarning("Confirmação obrigatória",
                "Confirme somente o período efetivamente trabalhado.",
                parent=dialog)
            return
        try:
            a,b=bounds(profile,datestr.get(),begin.get(),end.get(),
                       dt.datetime.now(profile.zone))
            if not 3<=len(task.get().strip())<=64:
                raise ValueError("Descreva a atividade em até 64 caracteres")
        except ValueError as exc:
            messagebox.showerror("Horários inválidos",str(exc),parent=dialog)
            return
        minutes=int((b-a).total_seconds()/60)
        if not messagebox.askyesno("Confirmar apontamento",
               f"Você confirma {minutes} minutos de trabalho HUMANO "
               f"no dia {datestr.get()}, das {begin.get()} às {end.get()}?\n\n"
               "O registro será INTERNO, NÃO FATURÁVEL e sem aprovação "
               "automática para remuneração.",parent=dialog):
            return
        try:
            dialog.configure(cursor="watch")
            dialog.update_idletasks()
            result=submit(profile_path=profile_path,env_path=env_path,
                          day=datestr.get(),start_hm=begin.get(),
                          end_hm=end.get(),task=task.get(),confirmed=True)
        except Exception as exc:
            detail=str(exc) if isinstance(exc,ValueError) else type(exc).__name__
            messagebox.showerror("Kimai",
                "Não foi possível conciliar: "+detail+
                ". Se houve resultado remoto incerto, NÃO reenviar: "
                "a fila deve ser reconciliada primeiro.",
                parent=dialog)
            return
        finally:
            dialog.configure(cursor="")
        messagebox.showinfo("Kimai",
            f"Resultado: {result['status']}\n"
            f"ID: {result['remote_id']}\n"
            f"Duração: {round(result['minutes'])} min\n"
            "Sem aprovação automática para remuneração.",parent=dialog)
        dialog.destroy()
    ttk.Button(dialog,text="Confirmar e registrar no Kimai",
               command=submit_clicked).pack(pady=12)
    dialog.grab_set()
    return dialog
