import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";
import laravel from "laravel-vite-plugin";

/**
 * Construtor visual de automações (Vue Flow). Build próprio, como Admin/WebForm: sai em
 * public/automations/build (versionado no git, igual ao public/admin/build) e só é carregado na tela
 * Configurações → Automação → Automações. Tem o próprio Vue (runtime) e monta um app isolado dentro
 * da tela do Admin, então não pesa nas outras telas nem depende do bundle do Admin.
 */
export default defineConfig(({ mode }) => {
    const envDir = "../../../";

    Object.assign(process.env, loadEnv(mode, envDir));

    return {
        build: {
            emptyOutDir: true,
        },

        envDir,

        server: {
            host: process.env.VITE_HOST || "localhost",
            port: process.env.VITE_PORT || 5175,
            cors: true,
        },

        plugins: [
            vue(),

            laravel({
                hotFile: "../../../public/automations-vite.hot",
                publicDirectory: "../../../public",
                buildDirectory: "automations/build",
                input: ["src/Resources/assets/js/flow.js"],
                refresh: true,
            }),
        ],
    };
});
