import { Component, OnInit, AfterViewInit, ElementRef, ViewChild, HostListener } from '@angular/core';
// import { CommonModule } from '@angular/common';
import { CommonModule, NgIf, NgForOf, NgClass, NgStyle } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TooltipModule } from 'primeng/tooltip';

/* PrimeNG */
import { ToolbarModule } from 'primeng/toolbar';
import { DatePickerModule } from 'primeng/datepicker';
import { SelectModule } from 'primeng/select';
import { ButtonModule } from 'primeng/button';
import { CardModule } from 'primeng/card';
import { ChartModule } from 'primeng/chart';
import { TableModule } from 'primeng/table';
import { ProgressBarModule } from 'primeng/progressbar';
import { PanelModule } from 'primeng/panel';
import { DividerModule } from 'primeng/divider';
import { TabsModule } from 'primeng/tabs';
import { SkeletonModule } from 'primeng/skeleton';

import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';


/* Chart.js */
import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';
Chart.register(ChartDataLabels);
/** -------- NPS Gauge plugin (aguja desde el centro, encima del texto) --------
 * En el chart usa:
 * plugins: { npsGauge: { enabled: true, value: <nps [-100..100]> } }
 */
const NpsGaugePlugin = {
  id: 'npsGauge',

  afterDraw(chart: any) {
    const opts = (chart?.options as any)?.plugins?.npsGauge;
    if (!opts?.enabled) return;

    const meta = chart.getDatasetMeta(0);
    const firstArc: any = meta?.data?.[0];
    if (!firstArc) return;

    const { x: cx, y: cy, outerRadius: r, innerRadius: r0 } =
      firstArc.getProps(['x', 'y', 'outerRadius', 'innerRadius'], true);

    const ctx = chart.ctx;

    // helpers
    const clamp = (n: number, a: number, b: number) => Math.min(b, Math.max(a, n));
    // const angleFromValue = (v: number) => {
    //   const t = (clamp(v, -100, 100) + 100) / 200; // 0..1
    //   return Math.PI * (1 - t);                    // π..0 (semicírculo)
    // };

    // Reemplaza tu angleFromValue por esta versión:
    const angleFromValue = (v: number) => {
      const t = (Math.min(100, Math.max(-100, v)) + 100) / 200; // 0 .. 1
      // Mapea: -100 → -π   (izquierda), 0 → -π/2 (arriba), 100 → 0 (derecha)
      return -Math.PI * (1 - t);
    };


    // estilos comunes
    const brandBlue = '#1e88e5'; //color aguja
    // const colorFor = (v: number) => v < -50 ? '#ef4444'
    //   : v < 0 ? '#f97316'
    //     : v < 25 ? '#f59e0b'
    //       : v < 50 ? '#a3e635'
    //         : v < 75 ? '#22c55e'
    //           : '#16a34a';
    // const brandBlue = colorFor(opts.value ?? 0); // color aguja dinámico según valor


    const labelFont = 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif';

    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    /* ===== ticks + números (cada 25) ===== */
    const tickOuter = r * 0.992;
    const tickInnerMinor = r * 0.94;
    const tickInnerMajor = r * 0.91;

    for (let v = -100; v <= 100; v += 25) {
      const a = angleFromValue(v);
      const cos = Math.cos(a), sin = Math.sin(a);

      const isZero = v === 0;
      ctx.strokeStyle = '#d1d5db';
      ctx.lineWidth = isZero ? 2 : 1;

      const xi = cx + cos * (isZero ? tickInnerMajor : tickInnerMinor);
      const yi = cy + sin * (isZero ? tickInnerMajor : tickInnerMinor);
      const xo = cx + cos * tickOuter;
      const yo = cy + sin * tickOuter;

      ctx.beginPath();
      ctx.moveTo(xi, yi);
      ctx.lineTo(xo, yo);
      ctx.stroke();

      ctx.fillStyle = isZero ? '#475569' : '#6b7280';
      ctx.font = (isZero ? '700 ' : '400 ') + '12px ' + labelFont;
      const xt = cx + cos * (r0 - 4);
      const yt = cy + sin * (r0 - 4);
      ctx.fillText(String(v), xt, yt);
    }

    /* ===== valor grande + etiqueta ===== */
    const value = typeof opts.value === 'number' ? Math.round(opts.value) : 0;
    // colocamos el texto un poco por encima del borde interior del anillo
    //Subir/bajar el número y la leyenda “NPS”
    const textY = cy - (r - r0) * 0.35; // <-- altura del número

    ctx.fillStyle = brandBlue;  //color numero 
    ctx.font = '800 64px ' + labelFont;
    ctx.fillText(String(value), cx, textY);

    ctx.fillStyle = '#6b7280';
    ctx.font = '600 20px ' + labelFont;
    ctx.fillText('NPS', cx, textY - 45); // <-- leyenda debajo del número

    /* ===== aguja desde el centro (DIBUJAR AL FINAL para que quede encima) ===== */
    const a = angleFromValue(opts.value ?? 0);

    // arranque ligeramente por encima del centro para no tapar el número
    const startR = r0 * 0.65;     // 35% del radio interior ojo // arranque (un poco dentro)
    const startX = cx + Math.cos(a) * startR;
    const startY = cy + Math.sin(a) * startR;

    // punta casi al borde exterior
    const tipR = r * 0.60;
    const tipX = cx + Math.cos(a) * tipR;
    const tipY = cy + Math.sin(a) * tipR;

    // línea de la aguja
    ctx.shadowColor = 'rgba(30,136,229,0.35)';
    ctx.shadowBlur = 3;
    ctx.strokeStyle = brandBlue;  //color aguja
    ctx.lineWidth = 3;  // grosor de la línea de la aguja
    ctx.lineCap = 'round';
    ctx.beginPath();
    ctx.moveTo(startX, startY);
    ctx.lineTo(tipX, tipY);
    ctx.stroke();

    // cabeza triangular
    const headLen = 10; // largo de la cabecita
    const headHalf = 7; // ancho de la cabecita
    const baseX = tipX - Math.cos(a) * headLen;
    const baseY = tipY - Math.sin(a) * headLen;
    const nx = Math.cos(a + Math.PI / 2) * headHalf;
    const ny = Math.sin(a + Math.PI / 2) * headHalf;

    ctx.fillStyle = brandBlue;  //color cabeza aguja
    ctx.beginPath();
    ctx.moveTo(tipX, tipY);
    ctx.lineTo(baseX - nx, baseY - ny);
    ctx.lineTo(baseX + nx, baseY + ny);
    ctx.closePath();
    ctx.fill();

    // pivote (en el centro geométrico)
    ctx.shadowBlur = 0;
    ctx.beginPath();
    ctx.fillStyle = '#1f2937';
    ctx.arc(cx, cy, 5, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
  }
};

/* Registrar una sola vez (evita duplicados en HMR) */
if (!(Chart as any)._npsGaugeRegistered) {
  Chart.register(NpsGaugePlugin);
  (Chart as any)._npsGaugeRegistered = true;
}








/* ===== Servicio ===== */
import { DashboardService, EncuestaMatrizRow } from '@/modules/Services/dashboard-service';

type Periodo = 'D' | 'W' | 'M' | 'Q';

interface Kpis {
  csat?: number;
  nps?: number;
  ces?: number;
  nps_breakdown?: { promotores_pct?: number; pasivos_pct?: number; detractores_pct?: number };
}

interface Overview {
  satisfaccion_avg_10?: { value: number; delta_abs_vs_prev: number | null };
  total_pqrs?: { value: number; delta_pct_vs_prev: number | null };
  pqrs_abiertas?: { value: number; delta_pct_vs_prev: number | null };
  encuestas_enviadas?: { value: number; delta_pct_vs_prev: number | null };
}

interface EncuestasResumen { programadas: number; enviadas: number; respondidas: number; tasa_pct: number; }
interface PqrsResumen { total: number; abiertos: number; en_proceso: number; escalados: number; cerrados: number; }

@Component({
  standalone: true,
  selector: 'app-dashboard',
  templateUrl: './dashboard.html',
  styleUrls: ['./dashboard.scss'],
  imports: [
    CommonModule, FormsModule, NgIf, NgForOf, NgClass, NgStyle,
    ToolbarModule, DatePickerModule, SelectModule, ButtonModule,
    CardModule, ChartModule, TableModule, ProgressBarModule, PanelModule, DividerModule,
    TabsModule, TooltipModule, SkeletonModule
  ]
})
export class Dashboard implements OnInit, AfterViewInit {
  /* ===== Filtros ===== */
  startDate: Date | null = null;
  endDate: Date | null = null;



  periodos = [
    { label: 'Diario', value: 'D' },
    { label: 'Semanal', value: 'W' },
    { label: 'Mensual', value: 'M' },
    { label: 'Trimestral', value: 'Q' }
  ];
  period: Periodo = 'M';

  segmentos = [
    { label: 'Por canal', value: 'canal' },
    { label: 'Por agencia', value: 'agencia' }
  ];
  segment = 'agencia';

  /* ===== Datos y series ===== */
  kpis: Kpis | null = null;
  overview: Overview | null = null;

  encuestasResumen: EncuestasResumen | null = null;
  pqrsResumen: PqrsResumen | null = null;

  encuestasPorCanal: Array<{ segmento: string; programadas: number; enviadas: number; respondidas: number; tasa_pct: number }> = [];
  encuestasPorAgencia: Array<{ segmento: string; programadas: number; enviadas: number; respondidas: number; tasa_pct: number }> = [];
  totalCanal = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };
  totalAgencia = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };

  // Tendencia (línea + barras)
  lineData: any = null;
  lineOptions: any = null;

  // Donut 1..5
  donutData: any = null;
  donutOptions: any = null;

  // Barras por segmento
  csatSegBar: any = null;
  npsSegBar: any = null;
  cesSegBar: any = null;
  csatBarOptions: any = null;
  npsBarOptions: any = null;
  cesBarOptions: any = null;

  // PQRs
  pqrsEstadoData: any = null;
  pqrsEstadoOpts: any = null;
  // === PQRs (listas para tablas) ===
  pqrsCat: Array<{ categoria: string; total: number }> = [];
  pqrsCatPadre: Array<{ categoria_padre: string; total: number }> = [];


  // Tab activo (0..4)
  selectedTabIndex = 0;

  // Series EVOLUCIÓN
  seriesCsat: any = null;
  seriesNps: any = null;
  seriesCes: any = null;
  seriesPqrs: any = null;
  seriesCorr: any = null;
  seriesLineOpts: any = null;
  seriesMixOpts: any = null;

  // NPS Gauge
  npsGaugeData: any = null;
  npsGaugeOptions: any = null;

// 28/09/2025

/* ===== NPS extra (tab 4) ===== */
npsEntidadRows: Array<{
  idEncuesta: number; encuesta: string; canal: string;
  idAsesor: number | null; asesor: string | null;
  idAgencia: number | null; agencia: string | null;
  total_nps: number; detractores: number; pasivos: number; promotores: number;
  detractores_pct: number; pasivos_pct: number; promotores_pct: number; nps: number;
}> = [];

npsClientesRows: Array<{
  idEncuesta: number; encuesta: string; canal: string;
  idAsesor: number | null; asesor: string | null;
  idAgencia: number | null; agencia: string | null;
  idCliente: number | null; cliente: string | null; celular: string | null; email: string | null;
  nps_val: number | null; clasificacion_nps: 'PROMOTOR' | 'PASIVO' | 'DETRACTOR' | 'SIN NPS';
}> = [];

loadingNpsExtra = false;
public filtroIdEncuesta: number | null = null; // opcional, para filtrar el resumen

// fin 28/09/2025


  /** Verifica si el donut tiene algún dato > 0 */
  hasDonutData(): boolean {
    const arr = this.donutData?.datasets?.[0]?.data as number[] | undefined;
    return Array.isArray(arr) && arr.some(v => Number(v) > 0);
  }


  constructor(private dashboardService: DashboardService) { }

  ngAfterViewInit() {
    setTimeout(() => { this.syncRowHeights(); this.attachRowResizeObservers(); }, 0);
  }

  async ngOnInit(): Promise<void> {
    const now = new Date();
    this.startDate = new Date(now.getFullYear(), now.getMonth(), 1);
    this.endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    const saved = localStorage.getItem('dashboard.activeTab');
    const idx = Number.isFinite(Number(saved)) ? Math.trunc(Number(saved)) : 0;
    this.selectedTabIndex = Math.min(Math.max(idx, 0), 4);

    this.refresh();
  }

  onPeriodoChange() { this.refresh(); }
  onSegmentChange() { this.refresh(); }

  // onTabChange(next: number | string) {
  //   const n = typeof next === 'string' ? parseInt(next, 10) : next;
  //   this.selectedTabIndex = Number.isFinite(n) ? Math.min(Math.max(n as number, 0), 4) : 0;
  //   localStorage.setItem('dashboard.activeTab', String(this.selectedTabIndex));

  //   if (this.selectedTabIndex === 3) this.loadSeries();
  //   if (this.selectedTabIndex === 4) this.updateNpsGauge(this.kpis?.nps ?? 0);
  //    this.loadNpsExtra(); // ← aquí
  // }
onTabChange(next: number | string) {
  const n = typeof next === 'string' ? parseInt(next, 10) : next;
  this.selectedTabIndex = Number.isFinite(n) ? Math.min(Math.max(n as number, 0), 4) : 0;
  localStorage.setItem('dashboard.activeTab', String(this.selectedTabIndex));

  if (this.selectedTabIndex === 3) this.loadSeries();
  if (this.selectedTabIndex === 4) {
    this.updateNpsGauge(this.kpis?.nps ?? 0);
    this.loadNpsExtra();           // ← aquí dentro
  }
}

  /* ===== Carga de datos ===== */
  refresh() {
    const inicio = this.startDate ? this.toYmd(this.startDate) : null;
    const fin = this.endDate ? this.toYmd(this.endDate) : null;
    const range: [string, string] | null = (inicio && fin) ? [inicio, fin] : null;

    this.dashboardService
      .getOverview({ range, period: this.period, segment: this.segment })
      .subscribe((res: any) => this.applyResponse(res));

    if (range) {
      this.dashboardService.getEncuestasResumen({ range })
        .subscribe(r => this.encuestasResumen = r);

      this.dashboardService.getPqrsResumen({ range })
        .subscribe(r => this.pqrsResumen = r);

      this.dashboardService.getEncuestasSegmentOverview('canal', inicio!, fin!)
        .subscribe(d => {
          this.encuestasPorCanal = d ?? [];
          this.totalCanal = this.computeTotals(this.encuestasPorCanal);
          setTimeout(() => { this.syncRowHeights(); this.attachRowResizeObservers(); }, 0);
        });

      this.dashboardService.getEncuestasSegmentOverview('agencia', inicio!, fin!)
        .subscribe(d => {
          this.encuestasPorAgencia = d ?? [];
          this.totalAgencia = this.computeTotals(this.encuestasPorAgencia);
          setTimeout(() => { this.syncRowHeights(); this.attachRowResizeObservers(); }, 0);
        });

      this.dashboardService.getEncuestasMatriz({ range })
        .subscribe((rows: EncuestaMatrizRow[]) => {
          this.encuestasMatriz = Array.isArray(rows) ? rows : [];
          this.encuestasMatrizTotals = this.computeTotalsSimple(this.encuestasMatriz);
        });

        if (this.selectedTabIndex === 4) this.loadNpsExtra();


      if (this.selectedTabIndex === 3) this.loadSeries();
    }
  }

  private applyResponse(res: any) {
    this.kpis = res.kpis;
    this.overview = res.overview;

    // Tendencia
    this.lineData = this.mapLineData(res.tendencia);
    this.lineOptions = this.makeLineOptions();

    // Donut
    this.donutData = this.mapDonutData(res.distribucion15);
    this.donutOptions = this.makeDonutOptions();

    // Barras por segmento
    this.csatSegBar = this.mapSegBar(res.csatPorSegmento, 'segmento', 'csat');
    this.npsSegBar = this.mapSegBar(res.npsPorSegmento, 'segmento', 'nps');
    this.cesSegBar = this.mapSegBar(res.cesPorSegmento, 'segmento', 'ces');

    this.csatBarOptions = this.makeSegBarOptions('%');
    this.npsBarOptions = this.makeSegBarOptions('nps');
    this.cesBarOptions = this.makeSegBarOptions('1-5');

    // PQRs
    this.pqrsEstadoData = this.mapPqrsEstado(res.pqrsPorEstado);
    this.pqrsEstadoOpts = this.makeBarNumberOptions('', 0);
    this.pqrsCat = res.pqrsPorCategoria || [];
    this.pqrsCatPadre = res.pqrsPorCategoriaPadre || [];

    // NPS gauge
    this.updateNpsGauge(this.kpis?.nps ?? 0);
  }

  /* ===== Helpers ===== */
  private toYmd(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  private mapLineData(src: any): any {
    if (!Array.isArray(src) || !src.length) return null;
    const labels = src.map((p: any) => String(p?.periodo ?? p?.period ?? p?.fecha ?? ''));
    const sat = src.map((p: any) => Number(p?.satisfaccion_5 ?? p?.satisfaccion ?? p?.avg ?? 0));
    const pqrs = src.map((p: any) => Number(p?.pqrs ?? p?.total ?? p?.cantidad ?? 0));
    return {
      labels,
      datasets: [
        { type: 'line', label: 'Satisfacción (1–5)', data: sat, tension: 0.3, borderWidth: 2, pointRadius: 3, yAxisID: 'ySat' },
        { type: 'bar', label: 'PQRs', data: pqrs, borderWidth: 1, yAxisID: 'yPqrs' }
      ]
    };
  }

  private makeLineOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top' },
        datalabels: {
          align: (ctx: any) => ctx.dataset.type === 'line' ? 'top' : 'end',
          anchor: (ctx: any) => ctx.dataset.type === 'line' ? 'end' : 'end',
          offset: (ctx: any) => ctx.dataset.type === 'line' ? 4 : 2,
          formatter: (v: number, ctx: any) =>
            ctx.dataset.type === 'line' ? this.fmtNum(v, 1) : this.fmtNum(v, 0),
        }
      },
      scales: {
        ySat: { type: 'linear', position: 'left', min: 0, max: 5, title: { display: true, text: 'Satisfacción (1–5)' } },
        yPqrs: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, beginAtZero: true, title: { display: true, text: 'PQRs' } },
        x: { ticks: { autoSkip: true, maxRotation: 0 } }
      }
    };
  }

  private mapDonutData(src: any): any {
    if (!src) return null;

    let labels: string[] = ['1', '2', '3', '4', '5'];
    let data: number[] = [];

    if (!Array.isArray(src) && typeof src === 'object') {
      const keys = ['cal_1_pct', 'cal_2_pct', 'cal_3_pct', 'cal_4_pct', 'cal_5_pct'];
      if (keys.every(k => k in src)) {
        data = keys.map(k => Number((src as any)[k]) || 0);
      } else {
        const buckets = new Array(5).fill(0);
        Object.keys(src).forEach(k => {
          const m = /(\d)/.exec(k);
          if (m) {
            const idx = parseInt(m[1], 10) - 1;
            if (idx >= 0 && idx < 5) buckets[idx] = Number((src as any)[k]) || 0;
          }
        });
        data = buckets;
      }
    } else if (Array.isArray(src)) {
      if (src.length && typeof src[0] === 'number') {
        data = (src as number[]).map(n => Number(n) || 0);
      } else {
        labels = src.map((it: any) => String(it?.label ?? it?.calificacion ?? it?.nombre ?? it?.id ?? it?.key ?? ''));
        data = src.map((it: any) => Number(it?.value ?? it?.count ?? it?.cantidad ?? it?.total ?? 0) || 0);
      }
    }

    return { labels, datasets: [{ data, borderWidth: 1 }] };
  }

  private makeDonutOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: {
        legend: { position: 'top' },
        datalabels: {
          color: '#111',
          font: { weight: '600' },
          formatter: (value: number, ctx: any) => this.donutPctFormatter(value, ctx),
        }
      }
    };
  }

  private mapSegBar(src: any[], labelKey: string, valueKey: string) {
    if (!Array.isArray(src) || !src.length) return null;
    const labels = src.map((r: any) => String(r?.[labelKey] ?? ''));
    const data = src.map((r: any) => Number(r?.[valueKey] ?? 0));
    return { labels, datasets: [{ label: '', data, borderWidth: 1 }] };
  }

  private makeSegBarOptions(mode: '%' | 'nps' | '1-5' = '%') {
    const isPct = mode === '%' || mode === 'nps';
    const maxByMode = mode === '1-5' ? 5 : 100;
    const minByMode = mode === 'nps' ? -100 : 0;

    return {
      indexAxis: 'y' as const,
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          min: minByMode,
          max: maxByMode,
          grid: { color: 'rgba(0,0,0,.05)' },
          ticks: { callback: (v: number) => isPct ? `${v}%` : `${v}` }
        },
        y: { ticks: { autoSkip: false } }
      },
      plugins: {
        legend: { display: false },
        datalabels: {
          anchor: 'end',
          align: 'end',
          offset: -50,
          clamp: true,
          formatter: (val: number) => {
            if (isPct) return `${val}%`;
            return Number.isFinite(val) ? (Math.round(val * 10) / 10).toString() : `${val}`;
          }
        }
      }
    };
  }

  private mapPqrsEstado(src: any[]) {
    if (!Array.isArray(src) || !src.length) return null;
    const labels = src.map(x => String(x?.estado ?? ''));
    const data = src.map(x => Number(x?.total ?? 0));
    return { labels, datasets: [{ label: 'PQRs', data, borderWidth: 1 }] };
  }

  private buildSeriesLineOpts() {
    this.seriesLineOpts = {
      responsive: true,
      maintainAspectRatio: false,
      scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#eee' } } },
      plugins: {
        legend: { display: true, position: 'top' },
        tooltip: { enabled: true },
        datalabels: {
          align: 'top',
          anchor: 'end',
          offset: 4,
          formatter: (v: number, ctx: any) => {
            const label = (ctx?.dataset?.label || '').toLowerCase();
            if (label.includes('csat')) return `${this.fmtNum(v, 0)}%`;
            if (label.includes('nps')) return this.fmtNum(v, 0);
            if (label.includes('ces')) return this.fmtNum(v, 1);
            return this.fmtNum(v, 0);
          },
        }
      }
    };
  }

  private buildSeriesMixOpts() {
    this.seriesMixOpts = {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false } },
        y: { type: 'linear', position: 'left', suggestedMin: 0, suggestedMax: 5, title: { display: true, text: 'Satisfacción (1–5)' } },
        y1: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'PQRs' } }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: { enabled: true },
        datalabels: {
          align: (ctx: any) => ctx.dataset.type === 'line' ? 'top' : 'end',
          anchor: 'end',
          offset: (ctx: any) => ctx.dataset.type === 'line' ? 4 : 2,
          formatter: (v: number, ctx: any) => ctx.dataset.type === 'line' ? this.fmtNum(v, 1) : this.fmtNum(v, 0),
        }
      }
    };
  }

  private toLineDataset(rows: Array<{ periodo: string; valor: number }>, label: string) {
    const labels = rows.map(r => r.periodo);
    const data = rows.map(r => Number(r.valor ?? 0));
    return { labels, datasets: [{ label, data, fill: false, tension: .3, pointRadius: 3, borderWidth: 2 }] };
  }

  private loadSeries() {
    const inicio = this.startDate ? this.toYmd(this.startDate) : null;
    const fin = this.endDate ? this.toYmd(this.endDate) : null;
    if (!inicio || !fin) return;
    const range: [string, string] = [inicio, fin];

    if (!this.seriesLineOpts) this.buildSeriesLineOpts();
    if (!this.seriesMixOpts) this.buildSeriesMixOpts();

    this.dashboardService.getSeries('csat_series', { range, period: this.period })
      .subscribe(rows => this.seriesCsat = this.toLineDataset(rows as any, 'CSAT (%)'));

    this.dashboardService.getSeries('nps_series', { range, period: this.period })
      .subscribe(rows => this.seriesNps = this.toLineDataset(rows as any, 'NPS'));

    this.dashboardService.getSeries('ces_series', { range, period: this.period })
      .subscribe(rows => this.seriesCes = this.toLineDataset(rows as any, 'CES (1–5)'));

    this.dashboardService.getSeries('pqrs_series', { range, period: this.period })
      .subscribe(rows => this.seriesPqrs = this.toLineDataset(rows as any, 'PQRs'));

    this.dashboardService.getSeries('csat_pqrs_corr', { range, period: this.period })
      .subscribe((rows: Array<{ periodo: string; csat: number; pqrs: number }>) => {
        const labels = rows.map(r => r.periodo);
        const csat = rows.map(r => Number(r.csat ?? 0));
        const pqrs = rows.map(r => Number(r.pqrs ?? 0));
        this.seriesCorr = {
          labels,
          datasets: [
            { type: 'line', label: 'Satisfacción (1–5)', data: csat, yAxisID: 'y', tension: .3, borderWidth: 2, pointRadius: 3 },
            { type: 'bar', label: 'PQRs', data: pqrs, yAxisID: 'y1', borderWidth: 1 }
          ]
        };
      });
  }

  /* ===== Helpers de datalabels ===== */
  private fmtNum = (v: any, dec = 0) => (typeof v === 'number' && isFinite(v)) ? v.toFixed(dec) : '';

  private donutPctFormatter = (value: number, ctx: any) => {
    const ds = ctx.chart.data.datasets[0];
    const total = (ds?.data as number[]).reduce((a, b) => a + (+b || 0), 0) || 1;
    const pct = (value * 100) / total;
    return `${pct.toFixed(0)}%`;
  };

  private makeBarNumberOptions(suffix = '', decimals = 0) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        datalabels: {
          anchor: 'end',
          align: 'end',
          offset: 2,
          clamp: true,
          formatter: (v: number) => `${this.fmtNum(v, decimals)}${suffix}`,
        }
      }
    };
  }

  getPctClass(p: number | null | undefined): string {
    const v = Number(p ?? 0);
    if (v >= 90) return 'pct-green';
    if (v >= 70) return 'pct-teal';
    if (v >= 50) return 'pct-orange';
    return 'pct-red';
  }
  pctBadgeClass(p: number | null | undefined): string {
    const v = Number(p ?? 0);
    if (v >= 90) return 'pct-badge pct-green';
    if (v >= 70) return 'pct-badge pct-teal';
    if (v >= 50) return 'pct-badge pct-orange';
    return 'pct-badge pct-red';
  }
  fmtPct(v: number | null | undefined): string {
    const n = Number(v ?? 0);
    return `${isFinite(n) ? n.toFixed(0) : '0'}%`;
  }

  @ViewChild('tblCanal', { read: ElementRef }) tblCanal!: ElementRef;
  @ViewChild('tblAgencia', { read: ElementRef }) tblAgencia!: ElementRef;
  private rafId: number | null = null;
  private rowObservers: ResizeObserver[] = [];

  @HostListener('window:resize') onWinResize() { this.syncRowHeights(); }

  private getBodyRows(root: HTMLElement) {
    const tb = root.querySelector('tbody');
    return tb ? Array.from(tb.querySelectorAll('tr')) as HTMLElement[] : [];
  }
  private clearRowHeights(rows: HTMLElement[]) {
    rows.forEach(r => {
      r.style.height = '';
      (Array.from(r.children) as HTMLElement[]).forEach(td => td.style.height = '');
    });
  }
  private setRowHeight(row: HTMLElement | undefined, h: number) {
    if (!row) return;
    row.style.height = h + 'px';
    (Array.from(row.children) as HTMLElement[]).forEach(td => td.style.height = h + 'px');
  }
  private syncRowHeights() {
    if (!this.tblCanal?.nativeElement || !this.tblAgencia?.nativeElement) return;
    const L = this.getBodyRows(this.tblCanal.nativeElement);
    const R = this.getBodyRows(this.tblAgencia.nativeElement);
    if (!L.length && !R.length) return;
    this.clearRowHeights(L); this.clearRowHeights(R);
    const maxLen = Math.max(L.length, R.length);
    for (let i = 0; i < maxLen; i++) {
      const l = L[i], r = R[i];
      const lh = l ? l.getBoundingClientRect().height : 0;
      const rh = r ? r.getBoundingClientRect().height : 0;
      const mh = Math.max(lh, rh);
      this.setRowHeight(l, mh); this.setRowHeight(r, mh);
    }
  }
  private attachRowResizeObservers() {
    this.rowObservers.forEach(o => o.disconnect());
    this.rowObservers = [];
    if (!this.tblCanal?.nativeElement || !this.tblAgencia?.nativeElement) return;

    const L = this.getBodyRows(this.tblCanal.nativeElement);
    const R = this.getBodyRows(this.tblAgencia.nativeElement);
    const maxLen = Math.max(L.length, R.length);

    const schedule = () => {
      if (this.rafId) cancelAnimationFrame(this.rafId);
      this.rafId = requestAnimationFrame(() => this.syncRowHeights());
    };

    for (let i = 0; i < maxLen; i++) {
      const l = L[i], r = R[i];
      const ro = new ResizeObserver(() => schedule());
      if (l) ro.observe(l);
      if (r) ro.observe(r);
      this.rowObservers.push(ro);
    }
    schedule();
  }

  encuestasMatriz: EncuestaMatrizRow[] = [];
  encuestasMatrizTotals = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };

  private computeTotals(list: Array<{ programadas: number; enviadas: number; respondidas: number }>) {
    const programadas = list.reduce((a, b) => a + (Number(b?.programadas) || 0), 0);
    const enviadas = list.reduce((a, b) => a + (Number(b?.enviadas) || 0), 0);
    const respondidas = list.reduce((a, b) => a + (Number(b?.respondidas) || 0), 0);
    const tasa_pct = enviadas > 0 ? Math.round((respondidas * 10000) / enviadas) / 100 : 0;
    return { programadas, enviadas, respondidas, tasa_pct };
  }
  private computeTotalsSimple(list: EncuestaMatrizRow[]) {
    const programadas = list.reduce((a, b) => a + (+b.programadas || 0), 0);
    const enviadas = list.reduce((a, b) => a + (+b.enviadas || 0), 0);
    const respondidas = list.reduce((a, b) => a + (+b.respondidas || 0), 0);
    const tasa_pct = enviadas > 0 ? Math.round((respondidas * 10000) / enviadas) / 100 : 0;
    return { programadas, enviadas, respondidas, tasa_pct };
  }


  private updateNpsGauge(nps: number | null | undefined) {
    const raw = Number(nps ?? 0);
    const v = Math.min(100, Math.max(-100, isFinite(raw) ? raw : 0));

    // 8 segmentos uniformes
    this.npsGaugeData = {
      labels: Array.from({ length: 8 }, () => ''),
      datasets: [{
        data: Array.from({ length: 8 }, () => 100),
        borderWidth: 8,
        borderColor: '#ffffff',
        borderJoinStyle: 'round' as const,
        borderRadius: 10,
        backgroundColor: [
          '#ef4444', '#f97316', '#f59e0b', '#facc15',
          '#a3e635', '#86efac', '#22c55e', '#16a34a'
        ],
        hoverBackgroundColor: [
          '#ef4444', '#f97316', '#f59e0b', '#facc15',
          '#a3e635', '#86efac', '#22c55e', '#16a34a'
        ]
      }]
    };



    this.npsGaugeOptions = {
      responsive: true,
      maintainAspectRatio: false,
      rotation: -90,        // semicírculo superior (en grados; si ya te funciona, déjalo)
      circumference: 180,
      cutout: '65%',
      plugins: {
        legend: { display: false },
        datalabels: { display: false },
        npsGauge: { enabled: true, value: v } // <-- activa aguja y envía valor
      }
    };

  }

// nps detalle

/** Color azul igual al del velocímetro */
get npsBrandColor(): string {
  return '#1e88e5';
}


/* ========= Utils de color (una sola vez en la clase) ========= */
// private clamp01(t: number) { return Math.max(0, Math.min(1, t)); }
 clamp01 = (t: number) => Math.max(0, Math.min(1, t));

// private hexToRgb(hex: string) {
//   const m = hex.replace('#', '');
//   const v = m.length === 3
//     ? m.split('').map(c => parseInt(c + c, 16))
//     : [parseInt(m.slice(0, 2), 16), parseInt(m.slice(2, 4), 16), parseInt(m.slice(4, 6), 16)];
//   return { r: v[0], g: v[1], b: v[2] };
// }

// private rgbToHex(r: number, g: number, bVal: number) {
//   const to = (n: number) => n.toString(16).padStart(2, '0');
//   return `#${to(r)}${to(g)}${to(bVal)}`;
// }

// /** Mezcla dos colores hex en proporción t (0..1) */
// private mixHex(hexA: string, hexB: string, t: number) {
//   const A = this.hexToRgb(hexA);
//   const B = this.hexToRgb(hexB);
//   const k = this.clamp01(t);
//   const r = Math.round(A.r + (B.r - A.r) * k);
//   const g = Math.round(A.g + (B.g - A.g) * k);
//   const bVal = Math.round(A.b + (B.b - A.b) * k);
//   return this.rgbToHex(r, g, bVal);
// }

hexToRgb = (hex: string) => {
    const m = hex.replace('#', '');
    const v = m.length === 3
      ? m.split('').map(c => parseInt(c + c, 16))
      : [parseInt(m.slice(0,2),16), parseInt(m.slice(2,4),16), parseInt(m.slice(4,6),16)];
    return { r: v[0], g: v[1], b: v[2] };
  };

  rgbToHex = (r: number, g: number, b: number) => {
    const to = (n: number) => n.toString(16).padStart(2, '0');
    return `#${to(r)}${to(g)}${to(b)}`;
  };

  mixHex = (a: string, b: string, t: number) => {
    const A = this.hexToRgb(a), B = this.hexToRgb(b), k = this.clamp01(t);
    const r = Math.round(A.r + (B.r - A.r) * k);
    const g = Math.round(A.g + (B.g - A.g) * k);
    const bb = Math.round(A.b + (B.b - A.b) * k);
    return this.rgbToHex(r, g, bb);
  };

/* ========= Gradientes por grupo ========= */

/** ------- Helpers de color públicos para el template ------- */
// public promColor = (p: number): string =>
//   this.mixHex('#86efac', '#16a34a', this.clamp01((p || 0) / 100));

// public pasivoColor = (p: number): string =>
//   this.mixHex('#86efac', '#facc15', this.clamp01((p || 0) / 100));

// public detrColor = (p: number): string =>
//   this.mixHex('#f97316', '#ef4444', this.clamp01((p || 0) / 100));
  promColor = (p: number) => this.mixHex('#86efac', '#16a34a', this.clamp01((p || 0) / 100));
  pasivoColor = (p: number) => this.mixHex('#86efac', '#facc15', this.clamp01((p || 0) / 100));
  detrColor = (p: number) => this.mixHex('#f97316', '#ef4444', this.clamp01((p || 0) / 100));


/** Aplica el color al ProgressBar (usado con [ngStyle]) */
// public barStyle = (color: string) => ({
//   '--bar-color': color,
//   '--p-progressbar-value-background': color,
//   '--p-progressbar-value-border-color': color
// }) as any;

  barStyle = (color: string) => ({
    '--bar-color': color,
    '--p-progressbar-value-background': color,
    '--p-progressbar-value-border-color': color,
  }) as any;

  
// nuevo nps detalle

// // private 
// loadNpsExtra() {
//   const inicio = this.startDate ? this.toYmd(this.startDate) : null;
//   const fin = this.endDate ? this.toYmd(this.endDate) : null;
//   if (!inicio || !fin) return;

//   this.loadingNpsExtra = true;

//   const range: [string, string] = [inicio, fin];

//   forkJoin([
//     this.dashboardService.getNpsResumenEntidad({ range, idEncuesta: this.filtroIdEncuesta ?? undefined }),
//     this.dashboardService.getNpsClientes({ range })
//   ])
//   .pipe(finalize(() => this.loadingNpsExtra = false))
//   .subscribe({
//     next: ([entidad, clientes]) => {
//       this.npsEntidadRows  = entidad  ?? [];
//       this.npsClientesRows = clientes ?? [];
//     },
//     error: () => {
//       this.npsEntidadRows  = [];
//       this.npsClientesRows = [];
//     }
//   });
// }
public loadNpsExtra = async (): Promise<void> => {
  const inicio = this.startDate ? this.toYmd(this.startDate) : null;
  const fin = this.endDate ? this.toYmd(this.endDate) : null;
  if (!inicio || !fin) return;

  this.loadingNpsExtra = true;
  const range = [inicio, fin] as [string, string];

  forkJoin({
    entidad: this.dashboardService.getNpsResumenEntidad({ range, idEncuesta: this.filtroIdEncuesta ?? undefined }),
    clientes: this.dashboardService.getNpsClientes({ range })
  }).subscribe({
    next: ({ entidad, clientes }) => {
      this.npsEntidadRows = entidad ?? [];
      this.npsClientesRows = clientes ?? [];
    },
    error: () => { this.loadingNpsExtra = false; },
    complete: () => { this.loadingNpsExtra = false; }
  });
};

public npsClass(n: number | null | undefined) {
  const v = Number(n ?? 0);
  if (v >= 50) return 'badge badge-green';
  if (v >= 0) return 'badge badge-lime';
  if (v >= -50) return 'badge badge-orange';
  return 'badge badge-red';
}
public clasifClass(c: string | null | undefined) {
  switch ((c ?? '').toUpperCase()) {
    case 'PROMOTOR': return 'badge badge-green';
    case 'PASIVO': return 'badge badge-amber';
    case 'DETRACTOR': return 'badge badge-red';
    default: return 'badge';
  }
}


// fin nuevo nps detalle

}
