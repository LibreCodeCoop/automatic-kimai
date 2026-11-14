"""Optional per-cooperator source registry.

A credential is not proof that personal ChatGPT/Claude histories are readable.
Only ActivityWatch is essential to automatic timesheet evidence. All provider
timestamps are supporting signals, never independently measured human hours.
"""
from __future__ import annotations
from pathlib import Path
import json

from kimai_client import save_private_json

SOURCES={
  "activitywatch":{
    "name":"ActivityWatch (aplicações, janelas e AFK)",
    "implemented":True,"default":True,"required":True,
    "note":"Instale o ActivityWatch para seu sistema e, se desejar, a extensão do navegador."},
  "github":{
    "name":"GitHub (eventos de contribuicao)",
    "implemented":True,"default":False,"required":False,
    "note":"Eventos de contribuição são contexto; commits automáticos não contam como horas."},
  "nextcloud_talk":{
    "name":"Nextcloud Talk (metadados)",
    "implemented":True,"default":False,"required":False,
    "note":"Usa senha de aplicativo pessoal do Nextcloud; reuniões não comprovam participação integral."},
  "nextcloud_calendar":{
    "name":"Nextcloud Calendar (agenda CalDAV)",
    "implemented":True,"default":False,"required":False,
    "note":"Compromissos pessoais excluem janelas; eventos agendados sem cobertura viram alertas de revisão, nunca horas automáticas."},
  "telegram":{
    "name":"Telegram (sessao autorizada localmente)",
    "implemented":True,"default":False,"required":False,
    "note":"Conecte sua conta com código/2FA e escolha somente as conversas que deseja acompanhar."},
  "chatgpt_browser":{
    "name":"ChatGPT (abas do navegador)",
    "implemented":True,"default":True,"required":False,
    "note":"A API OpenAI não dá acesso automático ao histórico do ChatGPT pessoal."},
  "claude_browser":{
    "name":"Claude (abas do navegador)",
    "implemented":True,"default":True,"required":False,
    "note":"A API Anthropic é separada da conta Claude.ai; não lê seu histórico privado."},
  "claude_code":{
    "name":"Claude Code (marcacoes por tarefa)",
    "implemented":False,"default":False,"required":False,
    "note":"Depende de eventos de execução confiáveis; processar IA ≠ horas humanas."},
}

def read_sources(profile_path:Path)->dict[str,bool]:
    target=Path(profile_path).parent/"sources.json"
    result={name:bool(meta["default"]) for name,meta in SOURCES.items()}
    if not target.exists():return result
    if target.is_symlink():raise ValueError("Symlinked provider config")
    data=json.loads(target.read_text(encoding="utf-8"))
    if not isinstance(data,dict) or set(data)-set(SOURCES):
        raise ValueError("Unknown source settings")
    for k,v in data.items():
        if type(v) is not bool:raise ValueError("Invalid source setting")
        result[k]=v
    if not result["activitywatch"]:
        raise ValueError("ActivityWatch required for automatic time classification")
    if any(result[k] and not SOURCES[k]["implemented"] for k in result):
        raise ValueError("An unsupported connector is enabled")
    return result

def save_sources(profile_path:Path,enabled:dict):
    if set(enabled)-set(SOURCES) or any(type(v) is not bool for v in enabled.values()):
        raise ValueError("Invalid sources")
    for k in enabled:
        if enabled[k] and not SOURCES[k]["implemented"]:
            raise ValueError("Connector not yet available: "+k)
    settings={k:bool(enabled.get(k,SOURCES[k]["default"])) for k in SOURCES}
    if not settings["activitywatch"]:raise ValueError("ActivityWatch cannot be disabled")
    save_private_json(Path(profile_path).parent/"sources.json",settings)
    return settings
