"""Optional attended event form: no terminal, no GPS, no AFK inference.

The worker reports attendance and may use a prior schedule as an estimate.
No read-only calendar event triggers an automatic Kimai write.
"""
from __future__ import annotations
import datetime as dt
import tkinter as tk
from tkinter import ttk,messagebox
from pathlib import Path

from .profile import Profile
from .attendance import AttendancePlan,apply_plan,planned_segments


def open_attendance(parent,profile_path:Path,env_path:Path):
    profile=Profile.read(profile_path)
    dialog=tk.Toplevel(parent)
    dialog.title("Registrar trabalho presencial ou fora do computador")
    dialog.geometry("700x570")
    ttk.Label(dialog,text="Registre um compromisso em que trabalhou pessoalmente, "
              "mas o computador não registrou atividade. A agenda não comprova presença. "
              "Indique intervalos de almoço/pausas e confira os horários reais.",
              wraplength=650).pack(pady=(16,11),padx=16)
    frame=ttk.Frame(dialog,padding=12)
    frame.pack(fill="x",padx=12)
    frame.columnconfigure(1,weight=1)
    vars={
        "day":tk.StringVar(value=dt.datetime.now(profile.zone).date().isoformat()),
        "label":tk.StringVar(),
        "start":tk.StringVar(value="09:00"),
        "end":tk.StringVar(value="17:00"),
        "breaks":tk.StringVar(value="12:00-13:00"),
        "schedule_source":tk.StringVar(value="Programa informado pelo cooperado"),
    }
    for i,(label,key) in enumerate((
        ("Data (AAAA-MM-DD)","day"),("Atividade (nome resumido)","label"),
        ("Início da jornada","start"),("Fim da jornada","end"),
        ("Pausas, ex: 12:00-13:00,15:00-15:15","breaks"),
        ("Origem dos horários (agenda ou horários reais)","schedule_source")
    )):
        ttk.Label(frame,text=label).grid(row=i,column=0,sticky="w",pady=7)
        ttk.Entry(frame,textvariable=vars[key],width=34).grid(
            row=i,column=1,sticky="ew",padx=12,pady=7)
    attended=tk.BooleanVar(value=False)
    exact=tk.BooleanVar(value=False)
    ttk.Checkbutton(dialog,variable=attended,
        text="Confirmo que participei e TRABALHEI neste compromisso.").pack(
            anchor="w",padx=21,pady=8)
    ttk.Checkbutton(dialog,variable=exact,
        text="Confirmei também os horários exatos; não são apenas previstos.").pack(
            anchor="w",padx=21,pady=5)
    ttk.Label(dialog,text="Caso os horários sejam apenas os do programa, "
              "serão registrados como estimativas operacionais internas; "
              "nunca como aprovação financeira.",
              wraplength=650).pack(anchor="w",padx=21,pady=7)

    def confirm():
        if not attended.get():
            messagebox.showwarning("Presença",
                "Confirme sua presença e trabalho realizado antes de continuar.",
                parent=dialog)
            return
        try:
            breaks=[]
            for part in vars["breaks"].get().split(","):
                part=part.strip()
                if not part:continue
                times=part.split("-")
                if len(times)!=2:raise ValueError("Pausas devem seguir HH:MM-HH:MM")
                breaks.append((times[0].strip(),times[1].strip()))
            plan=AttendancePlan(
                day=vars["day"].get().strip(),label=vars["label"].get().strip(),
                start=vars["start"].get().strip(),end=vars["end"].get().strip(),
                breaks=tuple(breaks),
                schedule_source=vars["schedule_source"].get().strip(),
                attendance_attested=True,exact_times_confirmed=exact.get())
            periods=planned_segments(plan,profile)
            preview=" / ".join(a+"–"+b for a,b in periods)
        except (ValueError,TypeError) as exc:
            messagebox.showerror("Programa inválido",str(exc),parent=dialog)
            return
        note=("Horários reais declarados" if exact.get()
              else "Horários ESTIMADOS conforme programa")
        if not messagebox.askyesno("Conferir trabalho presencial",
            f"{plan.day}: {plan.label}\n{preview}\n{note}\n\n"
            "Deseja registrar estes períodos como apontamentos INTERNOS, "
            "não faturáveis, sujeitos a validação posterior?",
            parent=dialog):
            return
        try:
            result=apply_plan(profile_path=profile_path,env_path=env_path,
                              plan=plan,attendance_confirmed=True)
        except Exception as exc:
            messagebox.showerror("Kimai",
                "Publicação não concluída: "+type(exc).__name__+
                ". Se a API retornou resultado incerto, não reenviar "
                "antes da reconciliação.",
                parent=dialog)
            return
        messagebox.showinfo("Kimai",
            "Períodos conciliados: "+str(len(result["results"]))+
            "\nIDs: "+", ".join(str(x["remote_id"]) for x in result["results"])+
            "\nSem aprovação financeira.",parent=dialog)
        dialog.destroy()
    ttk.Button(dialog,text="Conferir e registrar presença",command=confirm).pack(pady=14)
    dialog.grab_set()
    return dialog
