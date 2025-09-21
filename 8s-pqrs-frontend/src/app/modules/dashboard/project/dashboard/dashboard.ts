// import { CommonModule } from '@angular/common';
// import { Component } from '@angular/core';
// import { FormsModule } from '@angular/forms';
// import { RouterModule } from '@angular/router';
// import { ButtonModule } from 'primeng/button';
// import { ConfirmDialog } from 'primeng/confirmdialog';
// import { DialogModule } from 'primeng/dialog';
// import { IconFieldModule } from 'primeng/iconfield';
// import { InputIconModule } from 'primeng/inputicon';
// import { InputTextModule } from 'primeng/inputtext';
// import { ProgressSpinnerModule } from 'primeng/progressspinner';
// import { RippleModule } from 'primeng/ripple';
// import { TableModule } from 'primeng/table';
// import { ToastModule } from 'primeng/toast';



// @Component({
//   selector: 'app-dashboard',
//    imports: [
//         CommonModule,
//         FormsModule,
//         RouterModule,
//         ButtonModule,
//         ToastModule,
//         DialogModule,
//         ProgressSpinnerModule,
//         TableModule,
//         ConfirmDialog,
//         IconFieldModule,
//         InputIconModule,
//         InputTextModule,
//     ],
//   templateUrl: './dashboard.html',
//   styleUrl: './dashboard.scss'
// })
// export class Dashboard {

// }


// import { Component, OnInit } from '@angular/core';
// import { ApiService } from 'src/app/service/api.service';

// @Component({
//   selector: 'app-dashboard',
//   templateUrl: './dashboard.html',
//   styleUrls: ['./dashboard.scss']
// })
// export class DashboardComponent implements OnInit {

//   // Igual que en tus otros componentes:
//   private dashboardApi = `/controllers/dashboard.controller.php?op=`;

//   rango: Date[] | null = null;
//   loading = false;

//   kpis = { csat: 0, nps: 0, ces: 0 };

//   pqrsEstadoData: any = { labels: [], datasets: [{ data: [], label: 'PQRs' }] };
//   encuestasEstadoData: any = { labels: [], datasets: [{ data: [], label: 'Encuestas' }] };

//   constructor(private apiService: ApiService) {}

//   ngOnInit(): void { this.cargar(); }

//   cargar(): void {
//     this.loading = true;
//     const { ini, fin } = this.rangoFechas();

//     // KPIs
//     this.apiService.get(`${this.dashboardApi}kpis&fechaInicio=${ini}&fechaFin=${fin}`)
//       .subscribe({
//         next: (res: any) => this.kpis = res || { csat: 0, nps: 0, ces: 0 },
//         error: () => {},
//       });

//     // PQRs por estado
//     this.apiService.get(`${this.dashboardApi}pqrs_estado&fechaInicio=${ini}&fechaFin=${fin}`)
//       .subscribe({
//         next: (rows: any[]) => {
//           const labels = rows.map(r => r.estado);
//           const data   = rows.map(r => Number(r.total) || 0);
//           this.pqrsEstadoData = { labels, datasets: [{ data, label: 'PQRs' }] };
//         },
//         error: () => {},
//       });

//     // Encuestas por estado
//     this.apiService.get(`${this.dashboardApi}encuestas_estado&fechaInicio=${ini}&fechaFin=${fin}`)
//       .subscribe({
//         next: (rows: any[]) => {
//           const labels = rows.map(r => r.estado);
//           const data   = rows.map(r => Number(r.total) || 0);
//           this.encuestasEstadoData = { labels, datasets: [{ data, label: 'Encuestas' }] };
//         },
//         error: () => {},
//         complete: () => this.loading = false
//       });
//   }

//   private rangoFechas(): { ini: string, fin: string } {
//     if (!this.rango || this.rango.length < 2 || !this.rango[0] || !this.rango[1]) {
//       const hoy = new Date();
//       const ini = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
//       return { ini: this.fmt(ini), fin: this.fmt(hoy) };
//     }
//     return { ini: this.fmt(this.rango[0]), fin: this.fmt(this.rango[1]) };
//     }

//   private fmt(d: Date): string { return d.toISOString().slice(0, 10); }
// }


import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

// PrimeNG
import { CardModule } from 'primeng/card';
import { ChartModule } from 'primeng/chart';

import { ButtonModule } from 'primeng/button';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { ToastModule } from 'primeng/toast';
import { DashboardService } from '@/modules/Services/dashboard-service';
import { ToolbarModule } from 'primeng/toolbar';
import { ProgressBarModule } from 'primeng/progressbar';
import { TableModule } from 'primeng/table';
import { DatePicker } from 'primeng/datepicker';
import { Select } from 'primeng/select';


type DateRange = [Date | null, Date | null];

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [
     CommonModule,
    FormsModule,

    // PrimeNG que usas en el HTML mostrado
    Select,
    DatePicker,
    ToolbarModule,
    ButtonModule,

    // Otros que usa el dashboard
    CardModule,
    ChartModule,
    TableModule,
    ProgressBarModule,
    
  ],
  templateUrl: './dashboard.html',
  styleUrls: ['./dashboard.scss']
})
export class Dashboard implements OnInit {
  // filtros
  range: DateRange = [this.firstDayOfMonth(), new Date()];
  periodos = [
    { label: 'Mensual', value: 'M' },
    { label: 'Semanal', value: 'W' },
    { label: 'Trimestral', value: 'Q' },
    { label: 'Anual', value: 'Y' }
  ];
  period = 'M';
  segmentos = [
    { label: 'Por canal', value: 'canal' },
    { label: 'Por agencia', value: 'agencia' }
  ];
  segment = 'canal';

  // datos
  kpis: any;
  overview: any;
  tasaResp: any;
  encRes: any;
  pqrsRes: any;
  conversion: any;

  // charts
  lineData: any; lineOptions: any;
  csatSegBar: any; npsSegBar: any; cesSegBar: any; segBarOptions: any;
  donutData: any; donutOptions: any;
  pqrsEstadoData: any;

  // tablas
  pqrsCat: any[] = [];
  pqrsCatPadre: any[] = [];

  loading = false;

  constructor(private svc: DashboardService) { }

  ngOnInit(): void {
    this.buildChartOptions();
    this.fetchAll();
  }

  // Helpers fechas
  firstDayOfMonth(): Date {
    const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1);
  }
  toYMD(d: Date): string {
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }
  get paramsBase() {
    const [d1, d2] = this.range;
    return {
      fechaInicio: this.toYMD(d1 ?? this.firstDayOfMonth()),
      fechaFin: this.toYMD(d2 ?? new Date())
    };
  }

  refresh() { this.fetchAll(); }
  onPeriodoChange() { this.refresh(); }
  onSegmentChange() { this.refresh(); }

  private fetchAll(): void {
    this.loading = true;
    const base = this.paramsBase;

    // KPIs & overview
    this.svc.kpis(base).subscribe(r => this.kpis = r);
    this.svc.cardsOverview(base).subscribe(r => this.overview = r);

    // Resúmenes y tasas
    this.svc.tasaRespuesta(base).subscribe(r => this.tasaResp = r);
    this.svc.encuestasResumen(base).subscribe(r => this.encRes = r);
    this.svc.pqrsResumen(base).subscribe(r => this.pqrsRes = r);
    this.svc.encuestasConversion(base).subscribe(r => this.conversion = r);

    // Tendencia (línea + barras)
    this.svc.tendenciaSatisfaccionPqrs(base).subscribe((rows: any) => {
      const labels = rows.map((x: any) => x.periodo);
      const sat = rows.map((x: any) => x.satisfaccion_5);
      const pqrs = rows.map((x: any) => x.pqrs);
      this.lineData = {
        labels,
        datasets: [
          { type: 'line', label: 'Satisfacción (1–5)', data: sat, tension: 0.35, fill: false },
          { type: 'bar', label: 'PQRs', data: pqrs, yAxisID: 'y1' }
        ]
      };
    });

    // Segmentos
    const segParams = { ...base, segment: this.segment };
    this.svc.csatSegment(segParams).subscribe((rows: any) => {
      this.csatSegBar = {
        labels: rows.map((x: any) => x.segmento),
        datasets: [{ label: 'CSAT (%)', data: rows.map((x: any) => x.csat) }]
      };
    });
    this.svc.npsSegment(segParams).subscribe((rows: any) => {
      this.npsSegBar = {
        labels: rows.map((x: any) => x.segmento),
        datasets: [{ label: 'NPS', data: rows.map((x: any) => x.nps) }]
      };
    });
    this.svc.cesSegment(segParams).subscribe((rows: any) => {
      this.cesSegBar = {
        labels: rows.map((x: any) => x.segmento),
        datasets: [{ label: 'CES (1–5)', data: rows.map((x: any) => x.ces) }]
      };
    });

    // Donut 1..5
    this.svc.distribucionCalificaciones(base).subscribe((r: any) => {
      this.donutData = {
        labels: ['1', '2', '3', '4', '5'],
        datasets: [{ data: [r.cal_1_pct || 0, r.cal_2_pct || 0, r.cal_3_pct || 0, r.cal_4_pct || 0, r.cal_5_pct || 0] }]
      };
    });

    // PQRs por estado
    this.svc.pqrsEstado(base).subscribe((rows: any) => {
      this.pqrsEstadoData = {
        labels: rows.map((x: any) => x.estado),
        datasets: [{ label: 'PQRs', data: rows.map((x: any) => x.total) }]
      };
    });

    // tablas
    this.svc.pqrsPorCategoria(base).subscribe((rows: any) => this.pqrsCat = rows);
    this.svc.pqrsPorCategoriaPadre(base).subscribe((rows: any) => {
      this.pqrsCatPadre = rows;
      this.loading = false;
    });
  }

  private buildChartOptions() {
    this.lineOptions = {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      stacked: false,
      scales: {
        y: { type: 'linear', position: 'left', min: 0, max: 5, title: { display: true, text: 'Satisfacción (1–5)' } },
        y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'PQRs' } }
      },
      plugins: { legend: { position: 'top' } }
    };
    this.segBarOptions = {
      responsive: true,
      indexAxis: 'y',
      plugins: { legend: { display: false } },
      scales: { x: { beginAtZero: true } }
    };
    this.donutOptions = { responsive: true, cutout: '60%', plugins: { legend: { position: 'bottom' } } };
  }
}