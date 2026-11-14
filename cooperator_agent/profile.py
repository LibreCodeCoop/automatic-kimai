"""Per-cooperator settings without credentials, Kimai IDs or timezone hardcodes."""
from __future__ import annotations
from dataclasses import dataclass
from pathlib import Path
from zoneinfo import ZoneInfo
import json,re

@dataclass(frozen=True)
class Profile:
    profile_id: str
    timezone: str
    cache_dir: Path
    state_dir: Path
    customer_id: int
    project_id: int
    activity_id: int
    collector_backend: str="portable"
    lookback_days: int=3
    long_idle_minutes: int=10
    max_bridge_minutes: int=20
    min_session_minutes: int=15
    min_context_minutes: int=8
    max_session_hours: int=8
    settle_minutes: int=12
    capture_freshness_minutes: int=30
    work_topic_hints: tuple[str,...]=()
    work_domains: tuple[str,...]=()
    work_messenger_labels: tuple[str,...]=()
    allow_snapshots: bool=False
    alert_on_unrecorded_weekdays: bool=False
    sync_mode: str="review_only"
    allow_auto_nonbillable_preview: bool=False
    auto_min_work_minutes: int=8
    auto_max_unknown_fraction: float=0.55
    auto_max_session_hours: int=4

    @property
    def zone(self):
        return ZoneInfo(self.timezone)

    @classmethod
    def read(cls,filename:Path):
        path=Path(filename).expanduser()
        if path.is_symlink():raise ValueError("Profile cannot be symlink")
        raw=json.loads(path.read_text(encoding="utf-8"))
        fields=set(cls.__dataclass_fields__)
        if set(raw)-fields:raise ValueError("Unknown worker profile configuration")
        required=("profile_id","timezone","cache_dir","state_dir","customer_id","project_id","activity_id")
        if any(x not in raw for x in required):raise ValueError("Incomplete worker profile")
        for key in ("cache_dir","state_dir"):
            raw[key]=Path(raw[key]).expanduser().resolve()
        for field in ("work_topic_hints","work_domains","work_messenger_labels"):
            raw[field]=tuple(raw.get(field,()))
        obj=cls(**raw)
        obj.validate()
        return obj

    def validate(self):
        if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{1,47}",self.profile_id):
            raise ValueError("Invalid profile identifier")
        _=self.zone
        for k in ("customer_id","project_id","activity_id"):
            v=getattr(self,k)
            if type(v) is not int or v<1:raise ValueError("Invalid "+k)
        for k,lo,hi in (("lookback_days",1,7),("long_idle_minutes",5,60),
                        ("max_bridge_minutes",1,30),("min_session_minutes",10,90),
                        ("min_context_minutes",3,60),("max_session_hours",1,12),
                        ("settle_minutes",5,60),("capture_freshness_minutes",2,180),
                        ("auto_min_work_minutes",5,45),("auto_max_session_hours",1,8)):
            value=getattr(self,k)
            if type(value) is not int or not lo<=value<=hi:raise ValueError("Invalid "+k)
        if (type(self.auto_max_unknown_fraction) not in (int,float) or
            not .1<=self.auto_max_unknown_fraction<=.6):
            raise ValueError("Invalid automatic unknown-evidence threshold")
        if self.collector_backend != "portable":
            raise ValueError("Standalone package supports only the portable ActivityWatch backend")
        if self.sync_mode not in ("review_only","preview_nonbillable"):
            raise ValueError("Unknown sync mode")
        if self.sync_mode=="preview_nonbillable" and not self.allow_auto_nonbillable_preview:
            raise ValueError("Preview mode requires specific opt-in")
        if (type(self.allow_snapshots) is not bool or
            type(self.allow_auto_nonbillable_preview) is not bool or
            type(self.alert_on_unrecorded_weekdays) is not bool):
            raise ValueError("Invalid auto settings")
        if not all(isinstance(x,str) and 1<len(x)<=64 for x in self.work_topic_hints):
            raise ValueError("Invalid work topic hint")
        if len(self.work_domains)>40 or any(
            not isinstance(host,str) or
            not re.fullmatch(r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,30}",host)
            for host in self.work_domains):
            raise ValueError("Work domains must be exact lowercase DNS hosts")
        if len(self.work_messenger_labels)>40 or any(
            not isinstance(label,str) or not 1<len(label)<=80
            or "\n" in label or "\r" in label
            for label in self.work_messenger_labels):
            raise ValueError("Invalid work messenger label")
        if self.state_dir==self.cache_dir:raise ValueError("State dir cannot be evidence dir")
        return self
