// SPDX-License-Identifier: GPL-3.0-or-later
// Per-installation record: never synced with Nextcloud or sent to GitHub.
import { readFileSync, writeFileSync, renameSync, mkdirSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { randomUUID } from 'node:crypto';

const VERSION = /^\d+\.\d+\.\d+$/;

export function compareStableVersions(a, b) {
    if (!VERSION.test(a) || !VERSION.test(b)) return null;
    const left = a.split('.').map(Number);
    const right = b.split('.').map(Number);
    for (let index = 0; index < 3; index += 1) {
        if (!Number.isSafeInteger(left[index]) || !Number.isSafeInteger(right[index])) return null;
        if (left[index] !== right[index]) return Math.sign(left[index] - right[index]);
    }
    return 0;
}

/** Initial installation (or first upgrade from old builds) and future upgrades show notes once. */
export class ReleaseAnnouncementTracker {
    constructor({ version, packaged, storage }) {
        this.version = version;
        this.packaged = Boolean(packaged && VERSION.test(version) && storage);
        this.storage = storage;
        this.state = null;
        if (!this.packaged) return;
        try {
            const saved = storage.read();
            const highest = VERSION.test(saved?.highestVersion ?? '') ? saved.highestVersion : null;
            const pending = saved?.pendingVersion === version ? version : null;
            this.state = { highestVersion: highest || version, pendingVersion: highest ? pending : version };
            if (highest && compareStableVersions(version, highest) > 0) {
                this.state.highestVersion = version;
                this.state.pendingVersion = version;
            }
            if (!saved || this.state.highestVersion !== saved.highestVersion ||
                this.state.pendingVersion !== saved.pendingVersion) storage.write(this.state);
        } catch {
            // A read-only or corrupted state directory must not block app startup.
            this.state = null;
        }
    }

    status() {
        return { version: this.version, pending: Boolean(this.state?.pendingVersion === this.version) };
    }

    acknowledge() {
        if (!this.state || this.state.pendingVersion !== this.version) return this.status();
        const next = { ...this.state, pendingVersion: null };
        try {
            this.storage.write(next);
            this.state = next;
        } catch {
            // Keep the notification pending for the next launch if writing fails.
        }
        return this.status();
    }
}

/** Atomic, private metadata file in Electron's per-user application directory. */
export class FileReleaseAnnouncementStorage {
    constructor(userDataPath) {
        this.file = join(userDataPath, 'automatic-kimai-release-announcements.json');
    }

    read() {
        try { return JSON.parse(readFileSync(this.file, 'utf8')); }
        catch { return null; }
    }

    write(value) {
        const directory = dirname(this.file);
        mkdirSync(directory, { recursive: true, mode: 0o700 });
        const temp = this.file + '.' + randomUUID() + '.tmp';
        try {
            writeFileSync(temp, JSON.stringify(value), { encoding: 'utf8', flag: 'wx', mode: 0o600 });
            renameSync(temp, this.file);
        } finally {
            try { rmSync(temp, { force: true }); } catch { /* best-effort cleanup */ }
        }
    }
}
