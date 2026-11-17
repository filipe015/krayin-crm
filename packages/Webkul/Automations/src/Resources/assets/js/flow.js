/**
 * Entrada Vite do construtor visual de automações (carregada só na tela de Automações).
 *
 * O Admin do Krayin já tem o próprio app Vue (window.app). O construtor é um app Vue isolado,
 * montado dentro de um <div> que a tela do Admin entrega vazio (v-once), para o Vue Flow não
 * depender do bundle do Admin (que é pré-compilado e versionado em public/admin/build).
 *
 *   const editor = window.KrayinAutomationsFlow.mount(element, props);  // props: veja FlowEditor.vue
 *   editor.unmount();
 */
import { createApp, h } from "vue";
import FlowEditor from "./components/FlowEditor.vue";

import "@vue-flow/core/dist/style.css";
import "@vue-flow/core/dist/theme-default.css";
import "@vue-flow/controls/dist/style.css";
import "@vue-flow/minimap/dist/style.css";
import "../css/flow.css";

window.KrayinAutomationsFlow = {
    mount(element, props) {
        const app = createApp({ render: () => h(FlowEditor, props) });

        app.mount(element);

        return {
            unmount() {
                app.unmount();
            },
        };
    },
};

window.dispatchEvent(new CustomEvent("krayin-automations-flow:ready"));
