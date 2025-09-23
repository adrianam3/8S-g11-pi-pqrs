// import { Injectable } from '@angular/core';
// import { Observable } from 'rxjs';
// import { ApiService } from './api-service';


// @Injectable({ providedIn: 'root' })
// export class DashboardService {
//   private base = `/controllers/dashboard.controller.php?op=`;

//   constructor(private api: ApiService) {}

//   kpis(params: any) { return this.api.get(`${this.base}kpis`, { params }); }
//   cardsOverview(params: any) { return this.api.get(`${this.base}cards_overview`, { params }); }
//   tendenciaSatisfaccionPqrs(params: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, { params }); }

//   csatSegment(params: any) { return this.api.get(`${this.base}csat_segment`, { params }); }
//   npsSegment(params: any) { return this.api.get(`${this.base}nps_segment`, { params }); }
//   cesSegment(params: any) { return this.api.get(`${this.base}ces_segment`, { params }); }

//   distribucionCalificaciones(params: any) { return this.api.get(`${this.base}distribucion_calificaciones`, { params }); }
//   pqrsEstado(params: any) { return this.api.get(`${this.base}pqrs_estado`, { params }); }

//   pqrsPorCategoria(params: any) { return this.api.get(`${this.base}pqrs_por_categoria`, { params }); }
//   pqrsPorCategoriaPadre(params: any) { return this.api.get(`${this.base}pqrs_por_categoria_padre`, { params }); }

//   tasaRespuesta(params: any) { return this.api.get(`${this.base}tasa_respuesta`, { params }); }
//   encuestasResumen(params: any) { return this.api.get(`${this.base}encuestas_resumen`, { params }); }
//   pqrsResumen(params: any) { return this.api.get(`${this.base}pqrs_resumen`, { params }); }
//   encuestasConversion(params: any) { return this.api.get(`${this.base}encuestas_conversion`, { params }); }
// }


import { Injectable } from '@angular/core';
import { forkJoin, map, catchError, of } from 'rxjs';
import { ApiService } from './api-service'; // misma carpeta

type Periodo = 'D' | 'W' | 'M' | 'Q';

export interface OverviewResponse {
  kpis: any;
  overview: any;
  tendencia: { labels: string[]; sat15: number[]; pqrs: number[] };
  distribucion15: { counts?: number[] } | { [k: string]: number };
  csatPorSegmento: Array<{ label: string; value: number }>;
  npsPorSegmento: Array<{ label: string; value: number }>;
  cesPorSegmento: Array<{ label: string; value: number }>;
  pqrsPorEstado: Array<{ estado: string; total: number }>;
  pqrsPorCategoria: Array<{ categoria: string; total: number }>;
  pqrsPorCategoriaPadre: Array<{ categoria_padre: string; total: number }>;
}

@Injectable({ providedIn: 'root' })
export class DashboardService {
  private base = `/controllers/dashboard.controller.php?op=`;

  constructor(private api: ApiService) {}

  /** ===== Endpoints crudos (compatibles con tu backend) ===== */
  private kpis(params: any) { return this.api.get(`${this.base}kpis`, { params }); }
  private cardsOverview(params: any) { return this.api.get(`${this.base}cards_overview`, { params }); }
  private tendenciaSatisfaccionPqrs(params: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, { params }); }

  private csatSegment(params: any) { return this.api.get(`${this.base}csat_segment`, { params }); }
  private npsSegment(params: any)  { return this.api.get(`${this.base}nps_segment`,  { params }); }
  private cesSegment(params: any)  { return this.api.get(`${this.base}ces_segment`,  { params }); }

  private distribucionCalificaciones(params: any) { return this.api.get(`${this.base}distribucion_calificaciones`, { params }); }
  private pqrsEstado(params: any)     { return this.api.get(`${this.base}pqrs_estado`, { params }); }
  private pqrsPorCategoria(params: any)      { return this.api.get(`${this.base}pqrs_por_categoria`, { params }); }
  private pqrsPorCategoriaPadre(params: any) { return this.api.get(`${this.base}pqrs_por_categoria_padre`, { params }); }

  /**
   * Normaliza parámetros para el backend:
   * - Acepta range como:
   *    - string[]        -> ["YYYY-MM-DD","YYYY-MM-DD"]
   *    - [string,string] -> tupla
   *    - string          -> "YYYY-MM-DD,YYYY-MM-DD"
   * - Adjunta periodo y segmento si vienen definidos
   */
  private buildParams(input: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
    const params: any = {};

    // Tupla [ini, fin] (estricta)
    if (Array.isArray(input?.range) && input.range.length === 2 && typeof input.range[0] === 'string' && typeof input.range[1] === 'string') {
      params.range = `${input.range[0]},${input.range[1]}`;
    }
    // Array suelto string[] (aceptamos y normalizamos si hay al menos dos elementos)
    else if (Array.isArray(input?.range) && input.range.length > 0) {
      const ini = input.range[0] as string | undefined;
      const fin = input.range[1] as string | undefined;
      if (ini && fin) params.range = `${ini},${fin}`;
    }
    // String "ini,fin"
    else if (typeof input?.range === 'string') {
      params.range = input.range;
    }

    if (input?.period)  params.period  = input.period;
    if (input?.segment) params.segment = input.segment;

    // Pasar otros filtros sin pisar los anteriores
    Object.keys(input || {}).forEach(k => {
      if (!(k in params) && input[k] != null) params[k] = input[k];
    });

    return params;
  }

  /**
   * Punto único para el Dashboard.
   * Devuelve el objeto consolidado que consume el componente:
   * {
   *   kpis, overview, tendencia, distribucion15,
   *   csatPorSegmento, npsPorSegmento, cesPorSegmento,
   *   pqrsPorEstado, pqrsPorCategoria, pqrsPorCategoriaPadre
   * }
   */
  getOverview(args: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
    const params = this.buildParams(args);

    return forkJoin({
      kpis:                    this.kpis(params).pipe(catchError(() => of(null))),
      cards:                   this.cardsOverview(params).pipe(catchError(() => of(null))),
      tendencia:               this.tendenciaSatisfaccionPqrs(params).pipe(catchError(() => of(null))),
      dist:                    this.distribucionCalificaciones(params).pipe(catchError(() => of(null))),
      csatSeg:                 this.csatSegment(params).pipe(catchError(() => of([]))),
      npsSeg:                  this.npsSegment(params).pipe(catchError(() => of([]))),
      cesSeg:                  this.cesSegment(params).pipe(catchError(() => of([]))),
      pqrsEstado:              this.pqrsEstado(params).pipe(catchError(() => of([]))),
      pqrsCat:                 this.pqrsPorCategoria(params).pipe(catchError(() => of([]))),
      pqrsCatPadre:            this.pqrsPorCategoriaPadre(params).pipe(catchError(() => of([]))),
    }).pipe(
      map((r): OverviewResponse => {
        const overview = r.cards ?? {
          satisfaccion_avg_10: { value: 0, delta_abs_vs_prev: null },
          total_pqrs: { value: 0, delta_pct_vs_prev: 0 },
          pqrs_abiertas: { value: 0, delta_pct_vs_prev: 0 },
          encuestas_enviadas: { value: 0, delta_pct_vs_prev: 0 }
        };

        const tendencia = r.tendencia ?? { labels: [], sat15: [], pqrs: [] };
        const distribucion15 = r.dist ?? { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

        const csatPorSegmento = Array.isArray(r.csatSeg) ? r.csatSeg : [];
        const npsPorSegmento  = Array.isArray(r.npsSeg)  ? r.npsSeg  : [];
        const cesPorSegmento  = Array.isArray(r.cesSeg)  ? r.cesSeg  : [];

        const pqrsPorEstado   = Array.isArray(r.pqrsEstado)   ? r.pqrsEstado   : [];
        const pqrsPorCategoria      = Array.isArray(r.pqrsCat)      ? r.pqrsCat      : [];
        const pqrsPorCategoriaPadre = Array.isArray(r.pqrsCatPadre) ? r.pqrsCatPadre : [];

        return {
          kpis: r.kpis ?? { csat: 0, nps: 0, ces: 0, nps_breakdown: { promotores_pct: 0, pasivos_pct: 0, detractores_pct: 0 } },
          overview,
          tendencia,
          distribucion15,
          csatPorSegmento,
          npsPorSegmento,
          cesPorSegmento,
          pqrsPorEstado,
          pqrsPorCategoria,
          pqrsPorCategoriaPadre
        } as OverviewResponse;
      })
    );
  }
}
