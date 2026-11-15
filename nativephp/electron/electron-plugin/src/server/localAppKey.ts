import { randomBytes } from 'crypto';
import { closeSync, existsSync, fsyncSync, lstatSync, mkdirSync, openSync, readFileSync, writeSync } from 'fs';
import { isAbsolute, join } from 'path';

const KEY_FILE = 'automatic-kimai-app-key';
const KEY_FORMAT = /^base64:[a-zA-Z0-9+/]{43}=$/;

/**
 * Persist one encryption key per OS user profile. No key is bundled inside
 * the installer, sent to a remote service or printed to application logs.
 */
export function loadOrCreateLocalAppKey(userDataPath: string): string {
    if (!userDataPath || !isAbsolute(userDataPath)) {
        throw new Error('Invalid local application data directory');
    }

    mkdirSync(userDataPath, { recursive: true, mode: 0o700 });
    const keyPath = join(userDataPath, KEY_FILE);

    const readExisting = (): string => {
        const stat = lstatSync(keyPath);
        if (!stat.isFile() || stat.isSymbolicLink()) {
            throw new Error('Invalid local application key file type');
        }
        if (process.platform !== 'win32' && (stat.mode & 0o077) !== 0) {
            throw new Error('Local application key has unsafe permissions');
        }
        const key = readFileSync(keyPath, 'utf8').trim();
        if (!KEY_FORMAT.test(key) || Buffer.from(key.slice(7), 'base64').length !== 32) {
            throw new Error('Invalid local application key format');
        }
        return key;
    };

    if (existsSync(keyPath)) {
        return readExisting();
    }
    // Never silently lose access to previously encrypted data after deletion
    // of the key file. Require manual recovery of the original key.
    if (existsSync(join(userDataPath, 'storage', 'app', 'private', 'automatic-kimai', 'secrets.enc'))) {
        throw new Error('Cannot generate a new key for existing encrypted application data');
    }

    const newKey = 'base64:' + randomBytes(32).toString('base64');
    let fd: number | undefined;
    try {
        fd = openSync(keyPath, 'wx', 0o600);
        writeSync(fd, newKey + '\n', undefined, 'utf8');
        fsyncSync(fd);
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== 'EEXIST') {
            throw error;
        }
        return readExisting();
    } finally {
        if (fd !== undefined) closeSync(fd);
    }
    return readExisting();
}
