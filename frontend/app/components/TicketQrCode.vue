<script setup lang="ts">
const props = defineProps<{ value: string, size?: number }>()

const dataUrl = ref<string | null>(null)
const failed = ref(false)

// Import dynamique : la lib `qrcode` (~30 Ko gzipped) n'est tirée que côté
// /account/tickets et seulement après hydration. Pas besoin de @perf-debt:
// ce comportement-là est déjà l'optim "naturelle" — la spec §8 ne l'exige
// pas en mode starter.
async function render() {
  try {
    const { default: QRCode } = await import('qrcode')
    dataUrl.value = await QRCode.toDataURL(props.value, {
      margin: 1,
      width: props.size ?? 160,
    })
  }
  catch {
    failed.value = true
  }
}

onMounted(render)
watch(() => props.value, render)
</script>

<template>
  <div class="inline-block bg-white p-2 rounded">
    <img
      v-if="dataUrl"
      :src="dataUrl"
      :alt="`QR code ${value}`"
      :width="size ?? 160"
      :height="size ?? 160"
    >
    <div
      v-else-if="failed"
      class="h-40 w-40 grid place-items-center text-xs text-slate-500"
    >
      QR indisponible
    </div>
    <div
      v-else
      class="h-40 w-40 animate-pulse bg-slate-100"
    />
  </div>
</template>
