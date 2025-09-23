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

/* Chart.js (auto) */
import Chart from 'chart.js/auto';

/* Servicio */
import { DashboardService } from '@/modules/Services/dashboard-service';

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
    TabsModule, TooltipModule
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

  constructor(private dashboardService: DashboardService) { }

  async ngOnInit(): Promise<void> {
    // Rango por defecto: mes actual
    const now = new Date();
    this.startDate = new Date(now.getFullYear(), now.getMonth(), 1);
    this.endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);

    // Restaurar tab (como número)
    const saved = localStorage.getItem('dashboard.activeTab');
    this.selectedTabIndex = saved !== null && !isNaN(parseInt(saved, 10)) ? parseInt(saved, 10) : 0;

    // Cargar datos
    this.refresh();
  }

  onPeriodoChange() { this.refresh(); }
  onSegmentChange() { this.refresh(); }

  onTabChange(idx: number | string) {
    const n = typeof idx === 'string' ? parseInt(idx, 10) : idx;
    this.selectedTabIndex = Number.isFinite(n) ? (n as number) : 0;
    localStorage.setItem('dashboard.activeTab', String(this.selectedTabIndex));

    // Cargar series al entrar a "Evolución"
    if (this.selectedTabIndex === 3) {
      this.loadSeries();
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

    // KPIs adicionales
    if (range) {
      // this.dashboardService.getEncuestasTasa({ range })
      //   .subscribe(t => this.encuestasTasa = t);
      this.dashboardService.getEncuestasResumen({ range })
        .subscribe(r => this.encuestasResumen = r);


      this.dashboardService.getPqrsResumen({ range })
        .subscribe(r => this.pqrsResumen = r);

      if (this.selectedTabIndex === 3) {
        this.loadSeries();
      }
    }
  }

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
    this.pqrsCat = res.pqrsPorCategoria || [];
    this.pqrsCatPadre = res.pqrsPorCategoriaPadre || [];
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
      plugins: { legend: { position: 'top' } },
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
        legend: { position: 'top' }
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
    const maxByMode = mode === '%' ? 100 : (mode === 'nps' ? 100 : 5);
    const minByMode = mode === 'nps' ? -100 : 0;
    return {
      indexAxis: 'y' as const,
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: {
          min: minByMode, max: maxByMode,
          grid: { color: 'rgba(0,0,0,.05)' },
          ticks: { callback: (v: number) => mode === '%' ? `${v}%` : `${v}` }
        },
        y: { ticks: { autoSkip: false } }
      },
      plugins: { legend: { display: false } }
    };
  }

  private mapPqrsEstado(src: any[]) {
    if (!Array.isArray(src) || !src.length) return null;
    const labels = src.map(x => String(x?.estado ?? ''));
    const data = src.map(x => Number(x?.total ?? 0));
    return { labels, datasets: [{ label: 'PQRs', data, borderWidth: 1 }] };
  }

  /* ====== Series (EVOLUCIÓN) ====== */
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
        tooltip: { enabled: true }
      }
    };
  }

  private buildSeriesMixOpts() {
    this.seriesMixOpts = {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { display: false } },
        y: {
          type: 'linear',
          position: 'left',
          suggestedMin: 0,
          suggestedMax: 5,
          title: { display: true, text: 'Satisfacción (1–5)' }
        },
        y1: {
          type: 'linear',
          position: 'right',
          beginAtZero: true,
          grid: { drawOnChartArea: false },
          title: { display: true, text: 'PQRs' }
        }
      },
      plugins: {
        legend: { position: 'top' },
        tooltip: { enabled: true }
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
}
