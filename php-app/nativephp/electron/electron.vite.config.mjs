import { defineConfig, externalizeDepsPlugin } from 'electron-vite';
import { join } from 'path';
import { fileURLToPath } from 'node:url';

// NativePHP sets APP_PATH in packaged builds; local developer builds do not.
const appPath = process.env.APP_PATH || fileURLToPath(new URL('../../', import.meta.url));

export default defineConfig({
    main: {
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
});
