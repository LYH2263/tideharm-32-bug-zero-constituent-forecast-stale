<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getJSON, sendJSON } from '../api'

type Station = { slug: string; name: string }
type C = { name: string; speed_deg_per_hour: number; amplitude_m: number; phase_deg: number }
type Pt = { t_hours: number; level_m: number }
type DPt = { t_hours: number; formal_level_m: number; trial_level_m: number; diff_m: number }
type ResRow = { t_hours: number; observed_m: number; predicted_m: number; formal_predicted_m: number; residual_m: number; over: boolean }
type Decomp = {
  slug: string
  constituent: string
  removed_amplitude_m: number
  points: DPt[]
  residuals: { threshold_m: number; max_abs_residual_m: number; any_over: boolean; items: ResRow[] }
}

const HOURS = 48
const STEP = 30

const stations = ref<Station[]>([])
const slug = ref('')
const constituents = ref<C[]>([])
const picked = ref('')
const points = ref<Pt[]>([])
const decomp = ref<Decomp | null>(null)
const err = ref('')
const msg = ref('')
const busy = ref(false)

async function loadStations() {
  const d = await getJSON<{ items: Station[] }>('/api/stations')
  stations.value = d.items
  if (!slug.value && d.items[0]) slug.value = d.items[0].slug
}

async function loadStationDetail() {
  if (!slug.value) return
  const st = await getJSON<{ name: string; constituents: C[] }>(`/api/stations/${slug.value}`)
  constituents.value = st.constituents
}

async function loadForecast() {
  if (!slug.value) return
  const d = await getJSON<{ points: Pt[] }>(`/api/stations/${slug.value}/forecast?hours=${HOURS}&step_min=${STEP}`)
  points.value = d.points
}

async function loadDecompose() {
  decomp.value = null
  if (!slug.value || !picked.value) return
  const d = await getJSON<Decomp>(`/api/stations/${slug.value}/decompose?constituent=${encodeURIComponent(picked.value)}&hours=${HOURS}&step_min=${STEP}`)
  decomp.value = d
}

async function confirmZero() {
  if (!slug.value || !picked.value) return
  err.value = ''
  msg.value = ''
  busy.value = true
  try {
    const name = picked.value
    const z = await sendJSON<{ items: C[] }>(`/api/stations/${slug.value}/constituents/${encodeURIComponent(name)}/zero`, 'POST')
    if (z?.items) constituents.value = z.items
    msg.value = `已确认：${name} 振幅置零，正式预报已与拆解结果对齐`
    picked.value = ''
    decomp.value = null
  } catch (e) {
    err.value = String(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await loadStations()
    await Promise.all([loadStationDetail(), loadForecast()])
  } catch (e) {
    err.value = String(e)
  }
})

watch(slug, async () => {
  err.value = ''
  msg.value = ''
  picked.value = ''
  decomp.value = null
  try {
    await Promise.all([loadStationDetail(), loadForecast()])
  } catch (e) {
    err.value = String(e)
  }
})
watch(picked, () => loadDecompose().catch((e) => (err.value = String(e))))

const W = 720, H = 280, PAD = 34

function linePath(series: Array<[number, number]>, minX: number, maxX: number, minY: number, maxY: number): string {
  const sx = (x: number) => PAD + ((x - minX) / (maxX - minX || 1)) * (W - PAD * 2)
  const sy = (y: number) => H - PAD - ((y - minY) / (maxY - minY || 1)) * (H - PAD * 2)
  return series.map(([x, y], i) => `${i ? 'L' : 'M'}${sx(x).toFixed(2)},${sy(y).toFixed(2)}`).join(' ')
}

const formalPath = computed(() => {
  if (!points.value.length) return ''
  const xs = points.value.map((p) => p.t_hours)
  const ys = points.value.map((p) => p.level_m)
  return linePath(points.value.map((p) => [p.t_hours, p.level_m]), Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys))
})

const plotScales = computed(() => {
  const dp = decomp.value?.points ?? []
  if (!dp.length) return null
  const xs = dp.map((p) => p.t_hours)
  const ys = [...dp.map((p) => p.formal_level_m), ...dp.map((p) => p.trial_level_m)]
  return { minX: Math.min(...xs), maxX: Math.max(...xs), minY: Math.min(...ys), maxY: Math.max(...ys) }
})

const trialPath = computed(() => {
  const dp = decomp.value?.points ?? []
  const s = plotScales.value
  if (!s) return ''
  return linePath(dp.map((p) => [p.t_hours, p.trial_level_m]), s.minX, s.maxX, s.minY, s.maxY)
})

const formalOverlayPath = computed(() => {
  const dp = decomp.value?.points ?? []
  const s = plotScales.value
  if (!s) return ''
  return linePath(dp.map((p) => [p.t_hours, p.formal_level_m]), s.minX, s.maxX, s.minY, s.maxY)
})

const diffPath = computed(() => {
  const dp = decomp.value?.points ?? []
  if (!dp.length) return ''
  const xs = dp.map((p) => p.t_hours)
  const maxAbs = Math.max(...dp.map((p) => Math.abs(p.diff_m)), 1e-9)
  return linePath(dp.map((p) => [p.t_hours, p.diff_m]), Math.min(...xs), Math.max(...xs), -maxAbs, maxAbs)
})

const overCount = computed(() => decomp.value?.residuals.items.filter((r) => r.over).length ?? 0)
</script>
<template>
  <div class="page">
    <h1>潮位台</h1>
    <p class="lead">横轴时间、纵轴水位。选站看 {{ HOURS }} 小时合成预报；可选一个分潮做贡献拆解（内存试算，不写库）。</p>
    <p v-if="err" class="err">{{ err }}</p>
    <div class="panel" style="margin-bottom: 12px">
      <label>港口站
        <select v-model="slug">
          <option v-for="s in stations" :key="s.slug" :value="s.slug">{{ s.name }}</option>
        </select>
      </label>
      <label style="margin-left: 12px">拆解分潮
        <select v-model="picked">
          <option value="">不拆解（正式预报）</option>
          <option v-for="c in constituents" :key="c.name" :value="c.name">
            {{ c.name }}（振幅 {{ c.amplitude_m }} m）
          </option>
        </select>
      </label>
      <RouterLink v-if="slug" :to="`/stations/${slug}`" style="margin-left: 12px">分潮</RouterLink>
      <RouterLink v-if="slug" :to="`/stations/${slug}/residuals`" style="margin-left: 12px">残差</RouterLink>
    </div>

    <!-- 无拆解：正式预报 -->
    <div v-if="!decomp" class="panel plot">
      <svg viewBox="0 0 720 280" width="100%" height="280">
        <path :d="formalPath" fill="none" stroke="#3dd6c6" stroke-width="2" />
      </svg>
    </div>

    <!-- 拆解：正式 vs 去分潮试算 -->
    <template v-else>
      <div class="panel" style="margin-bottom: 12px">
        <div style="display: flex; gap: 18px; align-items: center; flex-wrap: wrap">
          <strong>剔除 {{ decomp.constituent }}（原振幅 {{ decomp.removed_amplitude_m }} m）后的试算</strong>
          <span><span style="color: #3dd6c6">━</span> 正式预报</span>
          <span><span style="color: #f0b429">━</span> 拆解试算</span>
          <span :class="decomp.residuals.any_over ? 'badge-bad' : 'badge-ok'">
            {{ decomp.residuals.any_over ? '存在超限点' : '无超限点' }}
          </span>
          <button :disabled="busy" @click="confirmZero">确认将 {{ decomp.constituent }} 振幅置零</button>
        </div>
        <p class="lead" style="margin: 8px 0 0">
          阈值 {{ decomp.residuals.threshold_m }} m ·
          试算最大 |残差| {{ decomp.residuals.max_abs_residual_m }} m ·
          超限点 {{ overCount }} / {{ decomp.residuals.items.length }}
        </p>
      </div>
      <p v-if="msg" class="badge-ok">{{ msg }}</p>
      <div class="panel plot" style="margin-bottom: 12px">
        <svg viewBox="0 0 720 280" width="100%" height="280">
          <path :d="formalOverlayPath" fill="none" stroke="#3dd6c6" stroke-width="2" />
          <path :d="trialPath" fill="none" stroke="#f0b429" stroke-width="2" stroke-dasharray="6 4" />
        </svg>
      </div>
      <div class="panel plot" style="margin-bottom: 12px">
        <div class="lead" style="padding: 6px 10px 0">拆解预报 − 正式预报：潮位差序列（m）</div>
        <svg viewBox="0 0 720 280" width="100%" height="250">
          <path :d="diffPath" fill="none" stroke="#7eb6c9" stroke-width="2" />
        </svg>
      </div>
      <div class="panel">
        <table>
          <thead><tr><th>t/h</th><th>实测</th><th>正式预报</th><th>试算预报</th><th>试算残差</th><th></th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in decomp.residuals.items" :key="i">
              <td>{{ r.t_hours }}</td>
              <td>{{ r.observed_m }}</td>
              <td>{{ r.formal_predicted_m }}</td>
              <td>{{ r.predicted_m }}</td>
              <td>{{ r.residual_m }}</td>
              <td :class="r.over ? 'badge-bad' : 'badge-ok'">{{ r.over ? '超限' : 'OK' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
