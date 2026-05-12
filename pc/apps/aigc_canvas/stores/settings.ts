import { ref } from 'vue'

export const canvasDarkMode = ref(true)

export function initCanvasSettings() {
    canvasDarkMode.value = true
}
