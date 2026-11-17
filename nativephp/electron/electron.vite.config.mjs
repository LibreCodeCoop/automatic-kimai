import { defineConfig, externalizeDepsPlugin } from 'electron-vite';
import { join } from 'path';
import { fileURLToPath } from 'node:url';

// NativePHP sets APP_PATH in packaged builds; local developer builds do not.
const appPath = process.env.APP_PATH || fileURLToPath(new URL('../../', import.meta.url));

// The main bundle locates the prepared app through this value. Define it here
// rather than in .env.* files, which are gitignored and rejected by the
// publication check. Production mirrors NativePHP's own .env.production.
export default defineConfig(({ mode }) => ({
    main: {
        define: {
            'import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH': JSON.stringify(
                mode === 'production' ? '../../../build' : process.env.NATIVEPHP_BUILD_PATH,
            ),
        },
        build: {
            rollupOptions: {
                plugins: [
                    {
                        name: 'watch-external',
                        buildStart() {
                            this.addWatchFile(
                                join(appPath, 'app', 'Providers', 'NativeAppServiceProvider.php'),
                            );
                        },
                    },
                ],
            },
        },
        plugins: [externalizeDepsPlugin()],
    },
}));
