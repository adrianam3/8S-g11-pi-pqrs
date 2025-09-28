import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
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

import { ElementRef, ViewChild, HostListener, AfterViewInit } from '@angular/core';

/* Chart.js (auto) */
import Chart from 'chart.js/auto';
import ChartDataLabels from 'chartjs-plugin-datalabels';   // <—
Chart.register(ChartDataLabels);
// Chart.register(Dashboard.GaugeNeedlePlugin);




// Plugin global (fuera de la clase)
const GaugeNeedlePlugin = {
  id: 'gaugeNeedle',
  // @ts-ignore
  afterDatasetDraw(chart: any, args: any, pluginOptions: any) {
    const value: number = Number(pluginOptions?.value ?? 0); // [-100..100]
    const meta = chart.getDatasetMeta(0);
    const firstArc: any = meta?.data?.[0];
    if (!firstArc) return;

    const { x: cx, y: cy, outerRadius: r } = firstArc.getProps(
      ['x', 'y', 'outerRadius'],
      true
    );

    const t = (Math.min(100, Math.max(-100, value)) + 100) / 200; // 0..1
    const angle = Math.PI * (1 - t);
    const needleLen = r * 0.9;
    const x = cx + Math.cos(angle) * needleLen;
    const y = cy + Math.sin(angle) * needleLen;

    const ctx = chart.ctx;
    ctx.save();
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#111';
    ctx.fillStyle = '#111';
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(x, y);
    ctx.stroke();
    ctx.beginPath();
    ctx.arc(cx, cy, 4, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }
};

// Registra el plugin AHORA que ya existe
Chart.register(GaugeNeedlePlugin);


/* Servicio */
import { DashboardService, EncuestaMatrizRow } from '@/modules/Services/dashboard-service';
// import { EncuestaMatrizRow } from '@/modules/Services/dashboard-service';

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

// interface EncuestasTasa { enviadas: number; respondidas: number; tasa_pct: number; }
interface EncuestasResumen { programadas: number; enviadas: number; respondidas: number; tasa_pct: number; }

interface PqrsResumen { total: number; abiertos: number; en_proceso: number; escalados: number; cerrados: number; }

@Component({
  standalone: true,
  selector: 'app-dashboard',
  templateUrl: './dashboard.html',
  styleUrls: ['./dashboard.scss'],
  imports: [
    CommonModule, FormsModule,
    ToolbarModule, DatePickerModule, SelectModule, ButtonModule,
    CardModule, ChartModule, TableModule, ProgressBarModule, PanelModule, DividerModule,
    TabsModule, TooltipModule, SkeletonModule
  ]
})
export class Dashboard implements OnInit {
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

  // encuestasTasa: EncuestasTasa | null = null;
  encuestasResumen: EncuestasResumen | null = null;
  pqrsResumen: PqrsResumen | null = null;

  encuestasPorCanal: Array<{ segmento: string; programadas: number; enviadas: number; respondidas: number; tasa_pct: number }> = [];
  encuestasPorAgencia: Array<{ segmento: string; programadas: number; enviadas: number; respondidas: number; tasa_pct: number }> = [];
  // Totales para cada tabla
  totalCanal = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };
  totalAgencia = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };


  // Tendencia (línea + barras)
  lineData: any = null;
  lineOptions: any = null;

  // Dona distribución (1..5)
  donutData: any = null;
  donutOptions: any = null;

  // Barras por segmento
  csatSegBar: any = null;
  npsSegBar: any = null;
  cesSegBar: any = null;
  csatBarOptions: any = null;
  npsBarOptions: any = null;
  cesBarOptions: any = null;

  // PQRs por estado
  pqrsEstadoData: any = null;

  // Tab activo
  selectedTabIndex: number = 0; // 0: Encuestas, 1: Satisfacción, 2: PQRs, 3: Evolución

  // Tablas PQRs
  pqrsCat: Array<{ categoria: string; total: number }> = [];
  pqrsCatPadre: Array<{ categoria_padre: string; total: number }> = [];

  // ====== Series (pestaña EVOLUCIÓN) ======
  seriesCsat: any = null;
  seriesNps: any = null;
  seriesCes: any = null;
  seriesPqrs: any = null;
  seriesCorr: any = null; // mixto CSAT vs PQRs
  seriesLineOpts: any = null;
  seriesMixOpts: any = null;

  // === NPS Gauge ===
  npsGaugeData: any = null;
  npsGaugeOptions: any = null;

  ngAfterViewInit() {
    setTimeout(() => { this.syncRowHeights(); this.attachRowResizeObservers(); }, 0);
  }


  constructor(private dashboardService: DashboardService) { 

  // Registrar el plugin de la aguja una sola vez
  if (!(Chart as any)._npsGaugeRegistered) {
    Chart.register(Dashboard.GaugeNeedlePlugin);
    (Chart as any)._npsGaugeRegistered = true;
  }

  }

  async ngOnInit(): Promise<void> {
    // Rango por defecto: mes actual
    const now = new Date();
    this.startDate = new Date(now.getFullYear(), now.getMonth(), 1);
    this.endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    // // Restaurar tab (como número)
    // const saved = localStorage.getItem('dashboard.activeTab');
    // this.selectedTabIndex = saved !== null && !isNaN(parseInt(saved, 10)) ? parseInt(saved, 10) : 0;

    // Restaurar tab (seguro) y acotar a 0..4
const saved = localStorage.getItem('dashboard.activeTab');
const parsed = Number(saved);
const idx = Number.isFinite(parsed) ? Math.trunc(parsed) : 0;
const MAX_TAB = 4; // 0:Encuestas, 1:Satisfacción, 2:PQRs, 3:Evolución, 4:NPS
this.selectedTabIndex = Math.min(Math.max(idx, 0), MAX_TAB);


    // Cargar datos
    this.refresh();
  }

  onPeriodoChange() { this.refresh(); }
  onSegmentChange() { this.refresh(); }

  // onTabChange(idx: number | string) {
  //   const n = typeof idx === 'string' ? parseInt(idx, 10) : idx;
  //   this.selectedTabIndex = Number.isFinite(n) ? (n as number) : 0;
  //   localStorage.setItem('dashboard.activeTab', String(this.selectedTabIndex));

  //   // Cargar series al entrar a "Evolución"
  //   if (this.selectedTabIndex === 3) {
  //     this.loadSeries();
  //   }
  // }

  onTabChange(next: number | string) {
  const n = typeof next === 'string' ? parseInt(next, 10) : next;
  const MAX_TAB = 4;
  this.selectedTabIndex = Number.isFinite(n) ? Math.min(Math.max(n as number, 0), MAX_TAB) : 0;
  localStorage.setItem('dashboard.activeTab', String(this.selectedTabIndex));

  if (this.selectedTabIndex === 3) this.loadSeries();
  if (this.selectedTabIndex === 4) this.updateNpsGauge(this.kpis?.nps ?? 0);
}


  /* ===== Carga de datos ===== */
  refresh() {
    const inicio = this.startDate ? this.toYmd(this.startDate) : null;
    const fin = this.endDate ? this.toYmd(this.endDate) : null;
    const range: [string, string] | null = (inicio && fin) ? [inicio, fin] : null;

    this.dashboardService
      .getOverview({ range, period: this.period, segment: this.segment })
      .subscribe((res: any) => this.applyResponse(res));

    // KPIs adicionales
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

        // this.updateNpsGauge(this.kpis?.nps ?? 0);

      if (this.selectedTabIndex === 3) {
        this.loadSeries();
      }
    }
  }

  pqrsEstadoOpts: any = null; // propiedad nueva

  private applyResponse(res: any) {
    this.kpis = res.kpis;
    this.overview = res.overview;

    // Tendencia
    this.lineData = this.mapLineData(res.tendencia);
    this.lineOptions = this.makeLineOptions();

    // Dona
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
    this.pqrsEstadoOpts = this.makeBarNumberOptions('', 0); //<--
    this.pqrsCat = res.pqrsPorCategoria || [];
    this.pqrsCatPadre = res.pqrsPorCategoriaPadre || [];

    this.updateNpsGauge(this.kpis?.nps);

  }

  /* ===== Helpers ===== */
  private toYmd(d: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  }

  /* ===== Mapas de datos principales ===== */
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
          // Mostrar valores en línea y barras con formato distinto
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
      cutout: '62%',
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
          ticks: {
            // ← eje X en % cuando sea NPS o %
            callback: (v: number) => isPct ? `${v}%` : `${v}`
          }
        },
        y: { ticks: { autoSkip: false } }
      },
      plugins: {
        legend: { display: false },
        // ← etiquetas visibles y “metidas” en el final de la barra
        datalabels: {
          anchor: 'end',
          align: 'end',
          offset: -50,        // mueve la etiqueta un poco hacia dentro
          clamp: true,       // evita que se dibuje fuera del chart area
          formatter: (val: number) => {
            if (isPct) return `${val}%`;
            // para escala 1–5 mostramos 1 decimal si aplica
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
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: '#eee' } }
      },
      plugins: {
        legend: { display: true, position: 'top' },
        tooltip: { enabled: true },
        datalabels: {
          align: 'top',
          anchor: 'end',
          offset: 4,
          formatter: (v: number, ctx: any) => {
            // Etiquetas “bonitas” según serie:
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
          formatter: (v: number, ctx: any) =>
            ctx.dataset.type === 'line' ? this.fmtNum(v, 1) : this.fmtNum(v, 0),
        }
      }
    };
  }

  private toLineDataset(rows: Array<{ periodo: string; valor: number }>, label: string) {
    const labels = rows.map(r => r.periodo);
    const data = rows.map(r => Number(r.valor ?? 0));
    return {
      labels,
      datasets: [{
        label,
        data,
        fill: false,
        tension: .3,
        pointRadius: 3,
        borderWidth: 2
      }]
    };
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

  /* Template helper */
  hasDonutData(): boolean {
    const arr = this.donutData?.datasets?.[0]?.data as any[] | undefined;
    return Array.isArray(arr) && arr.some(v => Number(v) > 0);
  }


  // ===== Helpers de datalabels =====
  private fmtNum = (v: any, dec = 0) =>
    (typeof v === 'number' && isFinite(v)) ? v.toFixed(dec) : '';

  /** Para DONUT: porcentaje de cada porción */
  private donutPctFormatter = (value: number, ctx: any) => {
    const ds = ctx.chart.data.datasets[0];
    const total = (ds?.data as number[]).reduce((a, b) => a + (+b || 0), 0) || 1;
    const pct = (value * 100) / total;
    return `${pct.toFixed(0)}%`;
  };

  /** Datalabels base para barras horizontales/verticales */
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
  private rowsSynced = false;

  @HostListener('window:resize')
  onWinResize() { this.syncRowHeights(); }

  // private syncRowHeights() {
  //   // asegúrate de que existen ambas tablas ya renderizadas
  //   if (!this.tblCanal?.nativeElement || !this.tblAgencia?.nativeElement) return;

  //   const leftRows = this.tblCanal.nativeElement.querySelectorAll('tbody tr');
  //   const rightRows = this.tblAgencia.nativeElement.querySelectorAll('tbody tr');

  //   if (!leftRows.length && !rightRows.length) return;

  //   // limpia alturas previas para recalcular
  //   leftRows.forEach((r: HTMLElement) => r.style.height = '');
  //   rightRows.forEach((r: HTMLElement) => r.style.height = '');

  //   const maxLen = Math.max(leftRows.length, rightRows.length);
  //   for (let i = 0; i < maxLen; i++) {
  //     const l = leftRows[i] as HTMLElement | undefined;
  //     const r = rightRows[i] as HTMLElement | undefined;
  //     const lh = l ? l.getBoundingClientRect().height : 0;
  //     const rh = r ? r.getBoundingClientRect().height : 0;
  //     const mh = Math.max(lh, rh);
  //     if (l) l.style.height = mh + 'px';
  //     if (r) r.style.height = mh + 'px';
  //   }
  // }
  // private rafId: number | null = null;
  // private canalObs?: MutationObserver;
  // private agObs?: MutationObserver;
  private rafId: number | null = null;
  private rowObservers: ResizeObserver[] = [];   // ← observadores por fila

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

    // limpiar para medir reales
    this.clearRowHeights(L);
    this.clearRowHeights(R);

    const maxLen = Math.max(L.length, R.length);
    for (let i = 0; i < maxLen; i++) {
      const l = L[i], r = R[i];
      const lh = l ? l.getBoundingClientRect().height : 0;
      const rh = r ? r.getBoundingClientRect().height : 0;
      const mh = Math.max(lh, rh);
      this.setRowHeight(l, mh);
      this.setRowHeight(r, mh);
    }
  }

  /** Crea ResizeObservers por fila para re-igualar cuando cambie el alto */
  private attachRowResizeObservers() {
    // limpia observers anteriores
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

    // primera igualación al enganchar
    schedule();
  }

  // Calcula totales y tasa global (respondidas/enviadas)
  private computeTotals(list: Array<{ programadas: number; enviadas: number; respondidas: number }>) {
    const programadas = list.reduce((a, b) => a + (Number(b?.programadas) || 0), 0);
    const enviadas = list.reduce((a, b) => a + (Number(b?.enviadas) || 0), 0);
    const respondidas = list.reduce((a, b) => a + (Number(b?.respondidas) || 0), 0);
    const tasa_pct = enviadas > 0 ? Math.round((respondidas * 10000) / enviadas) / 100 : 0; // 2 dec.
    return { programadas, enviadas, respondidas, tasa_pct };
  }


  // // propiedad para el dataset del nuevo dashboard
  // encuestasMatriz: Array<{
  //   idEncuesta: number; encuesta: string; canal: string; agencia: string; estadoEnvio: string;
  //   programadas: number; enviadas: number; respondidas: number; tasa_pct: number;
  // }> = [];

  // // totales de la tabla (opcional)
  // encuestasMatrizTotals = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };

  encuestasMatriz: EncuestaMatrizRow[] = [];
  encuestasMatrizTotals = { programadas: 0, enviadas: 0, respondidas: 0, tasa_pct: 0 };

  private computeTotalsSimple(list: EncuestaMatrizRow[]) {
    const programadas = list.reduce((a, b) => a + (+b.programadas || 0), 0);
    const enviadas = list.reduce((a, b) => a + (+b.enviadas || 0), 0);
    const respondidas = list.reduce((a, b) => a + (+b.respondidas || 0), 0);
    const tasa_pct = enviadas > 0 ? Math.round((respondidas * 10000) / enviadas) / 100 : 0;
    return { programadas, enviadas, respondidas, tasa_pct };
  }

  // nps 

  // Plugin simple para dibujar la aguja del gauge
 static GaugeNeedlePlugin = {
  id: 'gaugeNeedle',
  // @ts-ignore
  afterDatasetDraw(chart: any, args: any, pluginOptions: any) {
    const value: number = Number(pluginOptions?.value ?? 0); // [-100..100]
    const meta = chart.getDatasetMeta(0);
    const firstArc: any = meta?.data?.[0];
    if (!firstArc) return;

    const { x: cx, y: cy, outerRadius: r, innerRadius: r0 } = firstArc.getProps(
      ['x', 'y', 'outerRadius', 'innerRadius'],
      true
    );

    // Semicírculo superior: ángulo π (izq) a 0 (der)
    const t = (Math.min(100, Math.max(-100, value)) + 100) / 200; // 0..1
    const angle = Math.PI * (1 - t);

    const needleLen = r * 0.9; // largo de la aguja
    const x = cx + Math.cos(angle) * needleLen;
    const y = cy + Math.sin(angle) * needleLen;

    const ctx = chart.ctx;
    ctx.save();
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#111';
    ctx.fillStyle = '#111';

    // aguja
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(x, y);
    ctx.stroke();

    // pivote
    ctx.beginPath();
    ctx.arc(cx, cy, 4, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();
  }
};

private updateNpsGauge(nps: number | null | undefined) {
  const val = Number(nps ?? 0);
  const v = Math.min(100, Math.max(-100, isFinite(val) ? val : 0)); // clamp
  const filled = v + 100;     // 0..200
  const rest = 200 - filled;  // 200 total (−100..100)

  this.npsGaugeData = {
    labels: ['NPS', ''],
    datasets: [
      {
        data: [filled, rest],
        borderWidth: 0,
        // colores: “valor” y “restante”
        backgroundColor: ['#10b981', '#e5e7eb'],
        hoverBackgroundColor: ['#10b981', '#e5e7eb'],
      }
    ]
  };

  this.npsGaugeOptions = {
    responsive: true,
    maintainAspectRatio: false,
    // Semicírculo superior
    rotation: Math.PI,       // empieza a 180°
    circumference: Math.PI,  // 180°
    cutout: '70%',
    plugins: {
      legend: { display: false },
      datalabels: { display: false },
      // pasamos el valor al plugin de la aguja
      gaugeNeedle: { value: v }
    }
  };
}


}
