"""Friendly per-cooperator first-run setup; tkinter is in standard Python.

The setup never launches an HTTP server and never stores tokens in profile JSON.
Windows/macOS credentials require the OS keyring backend. Other providers are
opt-in, and unsupported providers are visibly disabled.
"""
from __future__ import annotations
import datetime as dt
import json
import subprocess
import sys
import tkinter as tk
import webbrowser
from pathlib import Path
from tkinter import ttk,messagebox,simpledialog
from zoneinfo import ZoneInfo
from tzlocal import get_localzone_name

from kimai_client import KimaiClient,save_private_json,minimal_catalog
from .paths import locations,private_dir
from .profile import Profile
from .providers import SOURCES,read_sources,save_sources
from .scheduler import install
from .secrets import read_credentials,save_credentials
from .runtime import main as runtime_main


def system_timezone_name() -> str:
    """Best-effort IANA timezone from the OS, never an organization default."""
    try:
        name=get_localzone_name()
        ZoneInfo(name)
        return name
    except Exception:
        # The Kimai user setting must still match before any submission.
        return "UTC"


class SetupWindow(tk.Tk):
    def __init__(self,profile_path:Path|None=None,env_path:Path|None=None):
        super().__init__()
        paths=locations()
        self.profile_path=Path(profile_path or paths.profile).expanduser()
        self.env_path=Path(env_path or paths.credentials).expanduser()
        self.title("Automatic Kimai — Configuração")
        self.geometry("860x700")
        self.minsize(760,640)
        self.saved=False
        self.verified=False
        self.catalog=None
        self.source_vars={}
        self.vars={}
        profile=None
        if self.profile_path.is_file():
            try:profile=Profile.read(self.profile_path)
            except Exception:pass
        self.profile=profile
        old=read_credentials(self.env_path,profile_id=profile.profile_id if profile else "user")
        self.vars={
            "profile_id":tk.StringVar(value=profile.profile_id if profile else "user"),
            "timezone":tk.StringVar(value=profile.timezone if profile else system_timezone_name()),
            "KIMAI_BASE_URL":tk.StringVar(value=old.get("KIMAI_BASE_URL","")),
            "KIMAI_API_TOKEN":tk.StringVar(value=""),
            "customer_id":tk.StringVar(value=str(profile.customer_id) if profile else ""),
            "project_id":tk.StringVar(value=str(profile.project_id) if profile else ""),
            "activity_id":tk.StringVar(value=str(profile.activity_id) if profile else ""),
            "NEXTCLOUD_URL":tk.StringVar(value=old.get("NEXTCLOUD_URL","")),
            "NEXTCLOUD_USERNAME":tk.StringVar(value=old.get("NEXTCLOUD_USERNAME","")),
            "NEXTCLOUD_APP_PASSWORD":tk.StringVar(value=""),
            "TELEGRAM_API_ID":tk.StringVar(value=old.get("TELEGRAM_API_ID","")),
            "TELEGRAM_API_HASH":tk.StringVar(value=""),
            "GITHUB_TOKEN":tk.StringVar(value=""),
            "OPENAI_API_KEY":tk.StringVar(value=""),
            "ANTHROPIC_API_KEY":tk.StringVar(value=""),
            "work_domains":tk.StringVar(value=", ".join(
                profile.work_domains if profile else ())),
            "work_messenger_labels":tk.StringVar(value=", ".join(
                profile.work_messenger_labels if profile else ())),
            "manual_write":tk.BooleanVar(value=old.get("KIMAI_MANUAL_PREVIEW_ENABLED")=="true"),
            "missing_weekdays":tk.BooleanVar(value=bool(profile and
                profile.alert_on_unrecorded_weekdays)),
            "auto_sync":tk.BooleanVar(value=bool(profile and
                profile.sync_mode=="preview_nonbillable" and
                profile.allow_auto_nonbillable_preview))
        }
        self.notebook=ttk.Notebook(self)
        self.notebook.pack(fill="both",expand=True,padx=12,pady=10)
        self.general=ttk.Frame(self.notebook,padding=18)
        self.sources=ttk.Frame(self.notebook,padding=18)
        self.automation=ttk.Frame(self.notebook,padding=18)
        self.notebook.add(self.general,text="1. Kimai")
        self.notebook.add(self.sources,text="2. Fontes de dados")
        self.notebook.add(self.automation,text="3. Automação")
        self._general()
        self._sources()
        self._automation()
        footer=ttk.Frame(self,padding=12)
        footer.pack(fill="x")
        ttk.Button(footer,text="Salvar configurações",command=self.save).pack(side="left")
        ttk.Button(footer,text="Testar conexão Kimai",command=self.test_kimai).pack(side="left",padx=8)
        ttk.Button(footer,text="Instalar sincronização 30 min",command=self.install_scheduler).pack(side="right")
        self.status=tk.StringVar(value="Credenciais existentes não são exibidas. Sem aprovação automática de remuneração.")
        ttk.Label(self,textvariable=self.status,wraplength=800).pack(fill="x",padx=16,pady=8)

    def _entry(self,parent,caption,name,row,*,password=False,width=55):
        ttk.Label(parent,text=caption).grid(row=row,column=0,sticky="w",pady=8)
        widget=ttk.Entry(parent,textvariable=self.vars[name],width=width,
                         show="•" if password else "")
        widget.grid(row=row,column=1,sticky="ew",padx=10,pady=8)
        return widget

    def _general(self):
        self.general.columnconfigure(1,weight=1)
        ttk.Label(self.general,text="Conecte seu próprio usuário do Kimai. Não use token de administrador.",
                  wraplength=670).grid(row=0,columnspan=2,sticky="w",pady=(0,16))
        self._entry(self.general,"Identificação local", "profile_id",1)
        self._entry(self.general,"Fuso horário (IANA)","timezone",2)
        self._entry(self.general,"Endereço HTTPS do Kimai","KIMAI_BASE_URL",3)
        self._entry(self.general,"Token pessoal (deixe em branco para manter)","KIMAI_API_TOKEN",4,password=True)
        self._entry(self.general,"Cliente interno — ID","customer_id",5)
        self._entry(self.general,"Projeto interno — ID","project_id",6)
        self._entry(self.general,"Atividade — ID","activity_id",7)
        ttk.Button(self.general,text="Escolher projeto e atividade pelo nome",
                   command=self.choose_catalog).grid(row=8,columnspan=2,sticky="w",pady=(8,0))
        ttk.Label(self.general,text="Após testar a conexão, os IDs devem pertencer ao projeto interno correto. "
                  "Todos os apontamentos automáticos serão não faturáveis e precisam de revisão.",
                  wraplength=690).grid(row=9,columnspan=2,sticky="w",pady=20)

    def _sources(self):
        # Scrollable on small laptops (Windows/macOS/Linux).
        canvas=tk.Canvas(self.sources,highlightthickness=0)
        scroll=ttk.Scrollbar(self.sources,orient="vertical",command=canvas.yview)
        canvas.configure(yscrollcommand=scroll.set)
        scroll.pack(side="right",fill="y")
        canvas.pack(side="left",fill="both",expand=True)
        panel=ttk.Frame(canvas,padding=8)
        frame_id=canvas.create_window((0,0),window=panel,anchor="nw")
        panel.bind("<Configure>",lambda e:canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.bind("<Configure>",lambda e:canvas.itemconfigure(frame_id,width=e.width))
        try: saved=read_sources(self.profile_path)
        except Exception:saved={name:meta["default"] for name,meta in SOURCES.items()}
        for i,(name,info) in enumerate(SOURCES.items()):
            enabled=tk.BooleanVar(value=bool(saved.get(name,info["default"])))
            self.source_vars[name]=enabled
            label=info["name"]+("" if info["implemented"] else " — em desenvolvimento")
            checkbox=ttk.Checkbutton(panel,text=label,variable=enabled)
            if info["required"] or not info["implemented"]:checkbox.state(["disabled"])
            checkbox.grid(row=i*2,column=0,sticky="w",pady=(6,0))
            ttk.Label(panel,text=info["note"],wraplength=700).grid(
                row=i*2+1,column=0,sticky="w",padx=22,pady=(0,5))
        ttk.Separator(panel).grid(row=17,column=0,sticky="ew",pady=10)
        ttk.Label(panel,text="Credenciais opcionais (somente quando necessário)",
                  font=("TkDefaultFont",10,"bold")).grid(row=18,column=0,sticky="w",pady=7)
        extras=ttk.Frame(panel)
        extras.grid(row=19,column=0,sticky="ew")
        ttk.Label(panel,text="Fontes confirmadas como TRABALHO pelo cooperado: "
                  "são evidências para apontamentos internos, não aprovação de horas.",
                  wraplength=680).grid(row=18,column=1,sticky="w",padx=12)
        for i,(label,key,hide) in enumerate((
            ("Nextcloud HTTPS","NEXTCLOUD_URL",False),
            ("Usuário Nextcloud","NEXTCLOUD_USERNAME",False),
            ("Senha de aplicativo Nextcloud","NEXTCLOUD_APP_PASSWORD",True),
            ("Telegram API ID","TELEGRAM_API_ID",False),
            ("Telegram API Hash (login individual com código)","TELEGRAM_API_HASH",True),
            ("GitHub token individual","GITHUB_TOKEN",True),
            ("OpenAI API key (NÃO lê histórico pessoal ChatGPT)","OPENAI_API_KEY",True),
            ("Anthropic API key (NÃO lê histórico Claude.ai)","ANTHROPIC_API_KEY",True),
        )):
            self._entry(extras,label,key,i,password=hide,width=37)
        self._entry(extras,"Domínios de trabalho aprovados (vírgulas)",
                    "work_domains",8,width=37)
        self._entry(extras,"Etiquetas de chats profissionais (vírgulas)",
                    "work_messenger_labels",9,width=37)
        ttk.Button(panel,text="Vincular Telegram e escolher conversas",
                   command=self.connect_telegram).grid(row=20,column=0,sticky="w",pady=14)

    def connect_telegram(self):
        if not self.profile_path.is_file() and not self.save():return
        try:
            import asyncio
            from .telegram_source import (authenticate,list_dialog_choices,
                                          save_watchlist,read_watchlist,
                                          TelegramNotAuthorized)
            profile=Profile.read(self.profile_path)
            credentials=self._current_secrets()
            if not credentials.get("TELEGRAM_API_ID") or not credentials.get("TELEGRAM_API_HASH"):
                raise ValueError("Informe e salve API ID e Hash do Telegram.")
            try:
                choices=asyncio.run(list_dialog_choices(profile,credentials))
            except TelegramNotAuthorized:
                phone=simpledialog.askstring("Telegram","Número com DDI (não será salvo)",
                                             parent=self)
                if not phone:return
                def ask_code():
                    return simpledialog.askstring("Telegram","Código recebido no Telegram",
                                                  parent=self)
                def ask_password():
                    return simpledialog.askstring("Telegram","Senha 2FA (não será salva)",
                                                  show="•",parent=self)
                asyncio.run(authenticate(profile,credentials,phone,ask_code,ask_password))
                choices=asyncio.run(list_dialog_choices(profile,credentials))
            if not choices:
                messagebox.showinfo("Telegram","Não foram encontradas conversas.")
                return
            selected_before=read_watchlist(profile)
            dialog=tk.Toplevel(self)
            dialog.title("Telegram — quais conversas acompanhar?")
            dialog.geometry("620x510")
            ttk.Label(dialog,text="Selecione SOMENTE as conversas autorizadas. "
                      "Títulos são exibidos apenas aqui; nunca são gravados.",
                      wraplength=580).pack(pady=14)
            box=tk.Listbox(dialog,selectmode=tk.MULTIPLE,width=77,height=19)
            box.pack(padx=12,pady=8,fill="both",expand=True)
            for i,choice in enumerate(choices):
                box.insert(tk.END,choice["title"])
                if choice["fingerprint"] in selected_before:
                    box.selection_set(i)
            def persist():
                selected=[choices[int(i)]["fingerprint"] for i in box.curselection()]
                save_watchlist(profile,selected)
                dialog.destroy()
                self.status.set("Telegram vinculado. Conversas selecionadas: "+
                                str(len(selected))+". Apenas metadados serão coletados.")
            ttk.Button(dialog,text="Salvar seleção",command=persist).pack(pady=9)
            dialog.grab_set()
        except Exception as exc:
            messagebox.showerror("Telegram","Autorização não concluída: "+
                                 type(exc).__name__+
                                 ". Instale o pacote opcional de integração Telegram.")

    def _automation(self):
        ttk.Label(self.automation,
            text="Cada 30 minutos, enquanto houver computador/sessão de usuário. "
                 "Após reiniciar, o histórico local é recuperado. Não é necessário deixá-lo ligado de madrugada.",
            wraplength=730).pack(anchor="w",pady=10)
        ttk.Label(self.automation,text="Intervalo: 30 minutos (fixo no piloto)").pack(anchor="w",pady=8)
        ttk.Checkbutton(self.automation,
                        text="Autorizar apontamentos internos PROVISÓRIOS automáticos (billable=false)",
                        variable=self.vars["auto_sync"]).pack(anchor="w",pady=10)
        ttk.Checkbutton(self.automation,
                        text="Permitir registro MANUAL de horas após confirmação individual",
                        variable=self.vars["manual_write"]).pack(anchor="w",pady=10)
        ttk.Checkbutton(self.automation,
                        text="Avisar quando um dia útil terminar sem apontamentos",
                        variable=self.vars["missing_weekdays"]).pack(anchor="w",pady=8)
        ttk.Label(self.automation,text="Sem esta autorização, o agendador gera apenas um relatório. "
                  "Não aprova remuneração cooperativista nem fatura clientes. "
                  "Pausas, IA autônoma e sessões duvidosas vão para revisão.",
                  wraplength=730).pack(anchor="w",pady=12)
        ttk.Button(self.automation,text="Executar somente uma coleta agora",
                   command=self.collect_now).pack(anchor="w",pady=9)
        ttk.Button(self.automation,text="Abrir relatório de revisão",
                   command=self.open_report).pack(anchor="w",pady=9)
        ttk.Button(self.automation,text="Revisar horas de trabalho não registradas",
                   command=self.review_unrecorded).pack(anchor="w",pady=9)
        ttk.Button(self.automation,text="Registrar trabalho presencial / fora do computador",
                   command=self.attendance_form).pack(anchor="w",pady=9)
        ttk.Button(self.automation,text="Atualizar análise sem escrever no Kimai",
                   command=self.refresh_review).pack(anchor="w",pady=9)
        ttk.Button(self.automation,text="Resumo da homologação de 7 dias",
                   command=self.show_trial).pack(anchor="w",pady=9)

    def _current_secrets(self):
        ident=self.vars["profile_id"].get().strip()
        saved=read_credentials(self.env_path,profile_id=ident)
        out=dict(saved)
        for key in ("KIMAI_BASE_URL","NEXTCLOUD_URL","NEXTCLOUD_USERNAME",
                    "TELEGRAM_API_ID"):
            out[key]=self.vars[key].get().strip()
        for key in ("KIMAI_API_TOKEN","NEXTCLOUD_APP_PASSWORD","TELEGRAM_API_HASH",
                    "GITHUB_TOKEN","OPENAI_API_KEY","ANTHROPIC_API_KEY"):
            new=self.vars[key].get().strip()
            if new:out[key]=new
        out["KIMAI_WRITE_ENABLED"]="true" if self.vars["auto_sync"].get() else "false"
        out["KIMAI_AUTO_PREVIEW_OPT_IN"]="true" if self.vars["auto_sync"].get() else "false"
        out["KIMAI_MANUAL_PREVIEW_ENABLED"]="true" if self.vars["manual_write"].get() else "false"
        out["KIMAI_SYNC_MODE"]="review_only"
        return out

    def choose_catalog(self):
        if not self.catalog:
            messagebox.showinfo("Projetos","Teste primeiro sua conexão com o Kimai.")
            return
        dialog=tk.Toplevel(self)
        dialog.title("Escolher cliente, projeto e atividade")
        dialog.geometry("700x450")
        ttk.Label(dialog,text="Escolha o projeto interno; depois escolha a atividade.").pack(pady=10)
        projects=ttk.Treeview(dialog,columns=("customer","id","name"),show="headings",height=9)
        for key,label,width in (("customer","Cliente",120),("id","ID",55),("name","Projeto",420)):
            projects.heading(key,text=label)
            projects.column(key,width=width)
        projects.pack(fill="both",expand=True,padx=15)
        catalog=self.catalog
        customers={c["id"]:c.get("name","") for c in catalog["customers"]}
        for p in catalog["projects"]:
            projects.insert("",tk.END,iid=str(p["id"]),
                values=(customers.get(p.get("customer_id"),"?"),p["id"],p.get("name","")))
        activities=ttk.Combobox(dialog,state="readonly",width=65)
        activities.pack(pady=12)
        activity_index={}
        def select_project(event=None):
            selection=projects.selection()
            if not selection:return
            chosen=int(selection[0])
            matches=[a for a in catalog["activities"] if a.get("project_id") in (None,chosen)]
            activity_index.clear()
            for a in matches:
                label=str(a["id"])+" — "+a.get("name","")
                activity_index[label]=a["id"]
            activities["values"]=list(activity_index)
            if activities["values"]:activities.current(0)
        projects.bind("<<TreeviewSelect>>",select_project)
        def choose():
            selection=projects.selection()
            if not selection or not activities.get():return
            project=next(p for p in catalog["projects"] if p["id"]==int(selection[0]))
            self.vars["customer_id"].set(str(project["customer_id"]))
            self.vars["project_id"].set(str(project["id"]))
            self.vars["activity_id"].set(str(activity_index[activities.get()]))
            dialog.destroy()
        ttk.Button(dialog,text="Utilizar esta seleção",command=choose).pack(pady=8)
        dialog.grab_set()

    def test_kimai(self):
        try:
            cfg=self._current_secrets()
            client=KimaiClient(cfg["KIMAI_BASE_URL"],cfg["KIMAI_API_TOKEN"])
            me,_=client.get("/users/me")
            catalog,warnings=minimal_catalog(client)
            if me.get("timezone")!=self.vars["timezone"].get():
                raise ValueError("O fuso do perfil precisa corresponder ao do Kimai")
            if warnings:raise ValueError("Catálogo incompleto")
            self.catalog=catalog
            self.verified=True
            self.status.set("Kimai conectado: usuário "+str(me.get("id"))+
                            "; "+str(len(catalog["projects"]))+" projetos disponíveis.")
            messagebox.showinfo("Kimai","Conexão validada com seu usuário. Confira os IDs do projeto interno.")
        except Exception as exc:
            self.verified=False
            messagebox.showerror("Falha na validação",
                "Não foi possível verificar usuário, fuso e catálogo do Kimai ("+
                type(exc).__name__+"). Nenhum token foi exibido.")

    def save(self):
        try:
            profile_id=self.vars["profile_id"].get().strip()
            profile_dir=self.profile_path.parent
            paths=locations()
            old=self.profile if self.profile and self.profile.profile_id==profile_id else None
            customer=int(self.vars["customer_id"].get())
            project=int(self.vars["project_id"].get())
            activity=int(self.vars["activity_id"].get())
            if self.vars["auto_sync"].get() and not self.verified:
                raise ValueError("Teste a conexão com o Kimai antes de autorizar a escrita.")
            if self.vars["auto_sync"].get() and self.catalog:
                match=next((p for p in self.catalog["projects"] if p["id"]==project),None)
                if not match or match.get("customer_id")!=customer:
                    raise ValueError("Cliente e projeto não correspondem.")
                if not any(a["id"]==activity and a.get("project_id") in (None,project)
                           for a in self.catalog["activities"]):
                    raise ValueError("Atividade não pertence ao projeto.")
            mode="preview_nonbillable" if self.vars["auto_sync"].get() else "review_only"
            profile=Profile(
                profile_id=profile_id,
                timezone=self.vars["timezone"].get().strip(),
                cache_dir=old.cache_dir if old else paths.cache_dir,
                state_dir=old.state_dir if old else paths.state_dir/profile_id,
                customer_id=customer,project_id=project,activity_id=activity,
                collector_backend=old.collector_backend if old else "portable",
                lookback_days=old.lookback_days if old else 3,
                long_idle_minutes=old.long_idle_minutes if old else 5,
                max_bridge_minutes=old.max_bridge_minutes if old else 20,
                min_session_minutes=old.min_session_minutes if old else 30,
                min_context_minutes=old.min_context_minutes if old else 8,
                max_session_hours=old.max_session_hours if old else 4,
                settle_minutes=old.settle_minutes if old else 12,
                capture_freshness_minutes=old.capture_freshness_minutes if old else 30,
                work_topic_hints=old.work_topic_hints if old else (),
                work_domains=tuple(x.strip().lower() for x in
                     self.vars["work_domains"].get().split(",") if x.strip()),
                work_messenger_labels=tuple(x.strip() for x in
                     self.vars["work_messenger_labels"].get().split(",") if x.strip()),
                allow_snapshots=True,
                alert_on_unrecorded_weekdays=bool(self.vars["missing_weekdays"].get()),
                sync_mode=mode,
                allow_auto_nonbillable_preview=bool(self.vars["auto_sync"].get()),
                auto_min_work_minutes=old.auto_min_work_minutes if old else 7,
                auto_max_unknown_fraction=old.auto_max_unknown_fraction if old else .56,
                auto_max_session_hours=old.auto_max_session_hours if old else 4)
            profile.validate()
            private_dir(profile_dir)
            private_dir(profile.cache_dir)
            private_dir(profile.state_dir)
            config=self._current_secrets()
            if not config.get("KIMAI_API_TOKEN"):
                raise ValueError("Informe o token individual do Kimai.")
            save_credentials(self.env_path,profile_id,config)
            save_sources(self.profile_path,{key:val.get() for key,val in self.source_vars.items()})
            raw={key:getattr(profile,key) for key in Profile.__dataclass_fields__}
            raw["cache_dir"]=str(profile.cache_dir)
            raw["state_dir"]=str(profile.state_dir)
            raw["work_topic_hints"]=list(profile.work_topic_hints)
            save_private_json(self.profile_path,raw)
            self.profile=profile
            self.saved=True
            self.status.set("Configurações salvas. Ative o sincronismo de 30 minutos se desejar.")
            messagebox.showinfo("Automatic Kimai","Configurações salvas no perfil local.")
            return True
        except Exception as exc:
            messagebox.showerror("Não foi possível salvar",str(exc))
            return False

    def install_scheduler(self):
        if not self.save():return
        try:
            result=install(self.profile_path,self.env_path,execute=True)
            messagebox.showinfo("Agendamento",
                "Sincronização local instalada: a cada 30 minutos e na inicialização.\n"+
                "Status de escrita: "+("prévia interna autorizada" if result["writes_opted_in"]
                                      else "somente revisão"))
        except Exception as exc:
            messagebox.showerror("Agendamento",type(exc).__name__+
                                 ": não foi possível instalar. Seu perfil foi preservado.")

    def collect_now(self):
        if not self.profile_path.is_file():
            messagebox.showwarning("Configuração","Salve seu perfil primeiro.")
            return
        if getattr(sys,"frozen",False):
            args=[sys.executable,"--sync","--profile",str(self.profile_path),
                  "--env-file",str(self.env_path),"--capture-only"]
            cwd=str(self.profile_path.parent)
        else:
            args=[sys.executable,"-m","cooperator_agent.runtime","--profile",
                  str(self.profile_path),"--env-file",str(self.env_path),
                  "--capture-only"]
            cwd=str(Path(__file__).resolve().parent.parent)
        try:
            subprocess.Popen(args,cwd=cwd)
            self.status.set("Coleta local iniciada. Abra o relatório para conferir depois.")
        except Exception as exc:
            messagebox.showerror("Coleta",type(exc).__name__)

    def show_trial(self):
        try:
            profile=Profile.read(self.profile_path)
            from .trial import weekly_summary
            result=weekly_summary(profile.state_dir/"trial_monitor.sqlite3",
                                  dt.datetime.now(profile.zone))
            lines=[
                "Situação: "+result["state"],
                "Execuções: "+str(result["cycles"]),
                "Publicações provisórias: "+str(result["writes"]),
                "Alertas de revisão: "+str(result["reviews"]),
                "Lacunas de atividade sem lançamento: "+str(result.get("coverage_gap_alerts",0)),
                "Compromissos de agenda sem cobertura (último ciclo): "+
                    str(result.get("latest_calendar_gaps",0)),
                "Intervalos para revisão atual: "+str(result.get("latest_review_candidates",0)),
                "Falhas de sincronização: "+str(result.get("failed_cycles",0)),
                "Homologação financeira: NÃO",
            ]
            messagebox.showinfo("Homologação de 7 dias","\n".join(lines))
        except Exception as exc:
            messagebox.showerror("Homologação","Resumo indisponível: "+type(exc).__name__)

    def refresh_review(self):
        if not self.profile_path.is_file():
            messagebox.showwarning("Configuração","Salve seu perfil primeiro.")
            return
        if getattr(sys,"frozen",False):
            command=[sys.executable,"--sync"]
            directory=self.profile_path.parent
        else:
            command=[sys.executable,"-m","cooperator_agent.runtime"]
            directory=Path(__file__).resolve().parent.parent
        command.extend(["--profile",str(self.profile_path),
                        "--env-file",str(self.env_path)])
        try:
            subprocess.Popen(command,cwd=str(directory))
            self.status.set("Revisão somente leitura iniciada; abra a lista após finalizar.")
        except Exception as exc:
            messagebox.showerror("Revisão",type(exc).__name__)

    def attendance_form(self):
        try:
            from .attendance_gui import open_attendance
            open_attendance(self,self.profile_path,self.env_path)
        except (ValueError,OSError,KeyError):
            messagebox.showerror("Presença",
                "Configure e salve seu perfil antes de registrar presença.")

    def review_unrecorded(self):
        try:
            from .review_gui import open_review
            open_review(self,self.profile_path,self.env_path)
        except (ValueError,OSError,KeyError) as exc:
            messagebox.showerror("Revisão",
                "Relatório indisponível. Atualize a análise e tente novamente.")

    def open_report(self):
        try:
            p=Profile.read(self.profile_path).state_dir/"latest_daily_plan.html"
            if not p.is_file():raise FileNotFoundError()
            webbrowser.open(p.as_uri())
        except Exception:
            messagebox.showinfo("Relatório","Ainda não existe relatório diário neste perfil.")


def main():
    import argparse
    parser=argparse.ArgumentParser()
    parser.add_argument("--profile",type=Path)
    parser.add_argument("--env-file",type=Path)
    args=parser.parse_args()
    app=SetupWindow(profile_path=args.profile,env_path=args.env_file)
    app.mainloop()

if __name__=="__main__":
    main()
