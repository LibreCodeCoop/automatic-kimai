"""Small cross-platform nonblocking process lock (Windows and POSIX)."""
from __future__ import annotations
import os
from pathlib import Path

def acquire(path:Path):
    file=Path(path)
    if file.is_symlink():raise ValueError("Refusing symlinked lock file")
    file.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd=os.open(file,os.O_CREAT|os.O_RDWR,0o600)
    try:
        if os.name=="nt":
            import msvcrt
            os.lseek(fd,0,os.SEEK_SET)
            msvcrt.locking(fd,msvcrt.LK_NBLCK,1)
        else:
            import fcntl
            os.fchmod(fd,0o600)
            fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return fd
    except BaseException:
        os.close(fd)
        raise

def release(fd:int):
    try:
        if os.name=="nt":
            import msvcrt
            os.lseek(fd,0,os.SEEK_SET)
            msvcrt.locking(fd,msvcrt.LK_UNLCK,1)
        else:
            import fcntl
            fcntl.flock(fd,fcntl.LOCK_UN)
    finally:
        os.close(fd)
