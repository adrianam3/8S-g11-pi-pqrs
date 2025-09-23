// import { Injectable } from '@angular/core';
// import { forkJoin, map, catchError, of, Observable } from 'rxjs';
// import { ApiService } from './api-service'; // misma carpeta

// // Extiendo Periodo para permitir anual si lo necesitas.
// export type Periodo = 'D' | 'W' | 'M' | 'Q' | 'Y';

// export interface OverviewResponse {
//   kpis: any;
//   overview: any;
//   tendencia: { labels: string[]; sat15: number[]; pqrs: number[] };
//   distribucion15: { counts?: number[] } | { [k: string]: number };
//   csatPorSegmento: Array<{ label: string; value: number }>;
//   npsPorSegmento: Array<{ label: string; value: number }>;
//   cesPorSegmento: Array<{ label: string; value: number }>;
//   pqrsPorEstado: Array<{ estado: string; total: number }>;
//   pqrsPorCategoria: Array<{ categoria: string; total: number }>;
//   pqrsPorCategoriaPadre: Array<{ categoria_padre: string; total: number }>;
// }

// /** Nuevos tipos para los endpoints adicionales (opcional tiparlos) */
// export interface EncuestasTasa { enviadas: number; respondidas: number; tasa_pct: number; }
// export interface PqrsResumen { total: number; abiertos: number; en_proceso: number; escalados: number; cerrados: number; }

// @Injectable({ providedIn: 'root' })
// export class DashboardService {
//   private base = `/controllers/dashboard.controller.php?op=`;

//   constructor(private api: ApiService) {}

//   /** ===== Endpoints crudos (compatibles con tu backend) ===== */
//   private kpis(params: any) { return this.api.get(`${this.base}kpis`, { params }); }
//   private cardsOverview(params: any) { return this.api.get(`${this.base}cards_overview`, { params }); }
//   private tendenciaSatisfaccionPqrs(params: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, { params }); }

//   private csatSegment(params: any) { return this.api.get(`${this.base}csat_segment`, { params }); }
//   private npsSegment(params: any)  { return this.api.get(`${this.base}nps_segment`,  { params }); }
//   private cesSegment(params: any)  { return this.api.get(`${this.base}ces_segment`,  { params }); }

//   private distribucionCalificaciones(params: any) { return this.api.get(`${this.base}distribucion_calificaciones`, { params }); }
//   private pqrsEstado(params: any)     { return this.api.get(`${this.base}pqrs_estado`, { params }); }
//   private pqrsPorCategoria(params: any)      { return this.api.get(`${this.base}pqrs_por_categoria`, { params }); }
//   private pqrsPorCategoriaPadre(params: any) { return this.api.get(`${this.base}pqrs_por_categoria_padre`, { params }); }

//   /** ===== NUEVOS endpoints (no rompen lo existente) ===== */
//   private encuestasTasa(params: any)   { return this.api.get(`${this.base}encuestas_tasa`, { params }); }
//   private pqrsResumen(params: any)     { return this.api.get(`${this.base}pqrs_resumen`, { params }); }
//   private series(op: 'csat_series'|'nps_series'|'ces_series'|'pqrs_series'|'csat_pqrs_corr', params: any) {
//     return this.api.get(`${this.base}${op}`, { params });
//   }

//   /**
//    * Normaliza parámetros para el backend:
//    * range admite:
//    *    - string[]        -> ["YYYY-MM-DD","YYYY-MM-DD"]
//    *    - [string,string] -> tupla
//    *    - string          -> "YYYY-MM-DD,YYYY-MM-DD"
//    * Adjunta period y segment si vienen definidos
//    */
//   private buildParams(input: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
//     const params: any = {};

//     if (Array.isArray(input?.range) && input.range.length === 2 && typeof input.range[0] === 'string' && typeof input.range[1] === 'string') {
//       params.range = `${input.range[0]},${input.range[1]}`;
//     } else if (Array.isArray(input?.range) && input.range.length > 0) {
//       const ini = input.range[0] as string | undefined;
//       const fin = input.range[1] as string | undefined;
//       if (ini && fin) params.range = `${ini},${fin}`;
//     } else if (typeof input?.range === 'string') {
//       params.range = input.range;
//     }

//     if (input?.period)  params.period  = input.period;
//     if (input?.segment) params.segment = input.segment;

//     Object.keys(input || {}).forEach(k => {
//       if (!(k in params) && input[k] != null) params[k] = input[k];
//     });

//     return params;
//   }

//   /**
//    * Punto único para el Dashboard (lo que ya usas).
//    * Devuelve el objeto consolidado que consume el componente.
//    */
//   getOverview(args: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
//     const params = this.buildParams(args);

//     return forkJoin({
//       kpis:                    this.kpis(params).pipe(catchError(() => of(null))),
//       cards:                   this.cardsOverview(params).pipe(catchError(() => of(null))),
//       tendencia:               this.tendenciaSatisfaccionPqrs(params).pipe(catchError(() => of(null))),
//       dist:                    this.distribucionCalificaciones(params).pipe(catchError(() => of(null))),
//       csatSeg:                 this.csatSegment(params).pipe(catchError(() => of([]))),
//       npsSeg:                  this.npsSegment(params).pipe(catchError(() => of([]))),
//       cesSeg:                  this.cesSegment(params).pipe(catchError(() => of([]))),
//       pqrsEstado:              this.pqrsEstado(params).pipe(catchError(() => of([]))),
//       pqrsCat:                 this.pqrsPorCategoria(params).pipe(catchError(() => of([]))),
//       pqrsCatPadre:            this.pqrsPorCategoriaPadre(params).pipe(catchError(() => of([]))),
//     }).pipe(
//       map((r): OverviewResponse => {
//         const overview = r.cards ?? {
//           satisfaccion_avg_10: { value: 0, delta_abs_vs_prev: null },
//           total_pqrs: { value: 0, delta_pct_vs_prev: 0 },
//           pqrs_abiertas: { value: 0, delta_pct_vs_prev: 0 },
//           encuestas_enviadas: { value: 0, delta_pct_vs_prev: 0 }
//         };

//         const tendencia = r.tendencia ?? { labels: [], sat15: [], pqrs: [] };
//         const distribucion15 = r.dist ?? { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

//         const csatPorSegmento = Array.isArray(r.csatSeg) ? r.csatSeg : [];
//         const npsPorSegmento  = Array.isArray(r.npsSeg)  ? r.npsSeg  : [];
//         const cesPorSegmento  = Array.isArray(r.cesSeg)  ? r.cesSeg  : [];

//         const pqrsPorEstado   = Array.isArray(r.pqrsEstado)   ? r.pqrsEstado   : [];
//         const pqrsPorCategoria      = Array.isArray(r.pqrsCat)      ? r.pqrsCat      : [];
//         const pqrsPorCategoriaPadre = Array.isArray(r.pqrsCatPadre) ? r.pqrsCatPadre : [];

//         return {
//           kpis: r.kpis ?? { csat: 0, nps: 0, ces: 0, nps_breakdown: { promotores_pct: 0, pasivos_pct: 0, detractores_pct: 0 } },
//           overview,
//           tendencia,
//           distribucion15,
//           csatPorSegmento,
//           npsPorSegmento,
//           cesPorSegmento,
//           pqrsPorEstado,
//           pqrsPorCategoria,
//           pqrsPorCategoriaPadre
//         } as OverviewResponse;
//       })
//     );
//   }

//   /** ========= NUEVOS MÉTODOS PÚBLICOS (para no romper lo anterior) ========= */

//   getEncuestasTasa(args: { range: string | [string, string] | string[] }) {
//     const params = this.buildParams(args);
//     return this.encuestasTasa(params) as Observable<EncuestasTasa>;
//   }

//   getPqrsResumen(args: { range: string | [string, string] | string[] }) {
//     const params = this.buildParams(args);
//     return this.pqrsResumen(params) as Observable<PqrsResumen>;
//   }

//   getSeries(
//     op: 'csat_series'|'nps_series'|'ces_series'|'pqrs_series'|'csat_pqrs_corr',
//     args: { range: string | [string, string] | string[]; period: Periodo }
//   ) {
//     const params = this.buildParams(args);
//     return this.series(op, params) as Observable<any[]>;
//   }
// }


// src/app/modules/Services/dashboard-service.ts
import { Injectable } from '@angular/core';
import { forkJoin, map, catchError, of, Observable } from 'rxjs';
import { ApiService } from './api-service';

// Periodo compatible con lo que ya usas
export type Periodo = 'D' | 'W' | 'M' | 'Q' | 'Y';

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

/* Tipos de los endpoints adicionales (opcional) */
export interface EncuestasTasa { enviadas: number; respondidas: number; tasa_pct: number; }
export interface PqrsResumen    { total: number; abiertos: number; en_proceso: number; escalados: number; cerrados: number; }

@Injectable({ providedIn: 'root' })
export class DashboardService {
  /** Nota: `this.base` ya incluye "?op=" para que agreguemos al final el nombre del op */
  private base = `/controllers/dashboard.controller.php?op=`;

  constructor(private api: ApiService) {}

  /* =======================
     Helpers de parámetros
     ======================= */

  /** Acepta range como tu array o string y devuelve un objeto plano listo para querystring */
  private buildParams(input: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
    const out: any = {};

    // Normalizamos range: "YYYY-MM-DD,YYYY-MM-DD"
    let rangeStr: string | null = null;
    if (Array.isArray(input?.range) && input.range.length >= 2 && typeof input.range[0] === 'string' && typeof input.range[1] === 'string') {
      rangeStr = `${input.range[0]},${input.range[1]}`;
    } else if (typeof input?.range === 'string') {
      rangeStr = input.range;
    }

    if (rangeStr) {
      out.range = rangeStr;

      // Además, por compatibilidad con PHP que a veces espera fechaInicio/fechaFin:
      const [ini, fin] = rangeStr.split(',');
      if (ini && fin) {
        out.fechaInicio = ini;
        out.fechaFin = fin;
      }
    }

    if (input?.period)  out.period  = input.period;
    if (input?.segment) out.segment = input.segment;

    // Pasar otros filtros sin anidar bajo "params"
    Object.keys(input || {}).forEach(k => {
      if (!(k in out) && input[k] != null) out[k] = input[k];
    });

    return out;
  }

  /* =======================
     Endpoints originales
     ======================= */

  private kpis(params: any)                 { return this.api.get(`${this.base}kpis`, this.buildParams(params)); }
  private cardsOverview(params: any)        { return this.api.get(`${this.base}cards_overview`, this.buildParams(params)); }
  private tendenciaSatisfaccionPqrs(p: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, this.buildParams(p)); }

  private csatSegment(p: any) { return this.api.get(`${this.base}csat_segment`, this.buildParams(p)); }
  private npsSegment(p: any)  { return this.api.get(`${this.base}nps_segment`,  this.buildParams(p)); }
  private cesSegment(p: any)  { return this.api.get(`${this.base}ces_segment`,  this.buildParams(p)); }

  private distribucionCalificaciones(p: any)   { return this.api.get(`${this.base}distribucion_calificaciones`, this.buildParams(p)); }
  private pqrsEstado(p: any)                   { return this.api.get(`${this.base}pqrs_estado`, this.buildParams(p)); }
  private pqrsPorCategoria(p: any)             { return this.api.get(`${this.base}pqrs_por_categoria`, this.buildParams(p)); }
  private pqrsPorCategoriaPadre(p: any)        { return this.api.get(`${this.base}pqrs_por_categoria_padre`, this.buildParams(p)); }

  /* =======================
     NUEVOS endpoints (fijos)
     ======================= */

  /** ¡Clave!: pasamos los params *aplanados*, no como { params: {...} } */
  private encuestasTasa(p: any) { return this.api.get(`${this.base}encuestas_tasa`, this.buildParams(p)); }
  private pqrsResumen(p: any)   { return this.api.get(`${this.base}pqrs_resumen`,   this.buildParams(p)); }

  private series(op: 'csat_series'|'nps_series'|'ces_series'|'pqrs_series'|'csat_pqrs_corr', p: any) {
    return this.api.get(`${this.base}${op}`, this.buildParams(p));
  }

  /* =======================
     Punto único para la vista
     ======================= */

  getOverview(args: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string; [k: string]: any }) {
    const params = this.buildParams(args);

    return forkJoin({
      kpis:          this.kpis(params).pipe(catchError(() => of(null))),
      cards:         this.cardsOverview(params).pipe(catchError(() => of(null))),
      tendencia:     this.tendenciaSatisfaccionPqrs(params).pipe(catchError(() => of(null))),
      dist:          this.distribucionCalificaciones(params).pipe(catchError(() => of(null))),
      csatSeg:       this.csatSegment(params).pipe(catchError(() => of([]))),
      npsSeg:        this.npsSegment(params).pipe(catchError(() => of([]))),
      cesSeg:        this.cesSegment(params).pipe(catchError(() => of([]))),
      pqrsEstado:    this.pqrsEstado(params).pipe(catchError(() => of([]))),
      pqrsCat:       this.pqrsPorCategoria(params).pipe(catchError(() => of([]))),
      pqrsCatPadre:  this.pqrsPorCategoriaPadre(params).pipe(catchError(() => of([]))),
    }).pipe(
      map((r): OverviewResponse => {
        const overview = r.cards ?? {
          satisfaccion_avg_10: { value: 0, delta_abs_vs_prev: null },
          total_pqrs:          { value: 0, delta_pct_vs_prev: 0 },
          pqrs_abiertas:       { value: 0, delta_pct_vs_prev: 0 },
          encuestas_enviadas:  { value: 0, delta_pct_vs_prev: 0 }
        };

        const tendencia     = r.tendencia ?? { labels: [], sat15: [], pqrs: [] };
        const distribucion15= r.dist ?? { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

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

  /* ========= métodos públicos extra (por si los llamas luego) ========= */

  // getEncuestasTasa(args: { range: string | [string, string] | string[] }) {
  //   return this.encuestasTasa(args) as Observable<EncuestasTasa>;
  // }

//   // ---- Encuestas (resumen) ----
// getEncuestasResumen(args: { range: string | [string, string] }) {
//   const params = Array.isArray(args.range)
//     ? { fechaInicio: args.range[0], fechaFin: args.range[1] }
//     : (() => { const [ini, fin] = args.range.split(','); return { fechaInicio: ini, fechaFin: fin }; })();

//   // backend: ?op=encuestas_resumen
//   return this.api.get<{ programadas:number; enviadas:number; respondidas:number; tasa_pct:number }>(
//     '/controllers/dashboard.controller.php',
//     { op: 'encuestas_resumen', ...params }
//   );
// }
// ---- Encuestas (resumen) ----
getEncuestasResumen(args: { range: string | [string, string] }) {
  // usa el mismo normalizador que ya tienes
  const params = this.buildParams(args);

  // usa la misma base que el resto de endpoints
  // /controllers/dashboard.controller.php?op=encuestas_resumen
  return this.api.get(`${this.base}encuestas_resumen`, params) as Observable<{
    programadas: number;
    enviadas: number;
    respondidas: number;
    tasa_pct: number;
  }>;
}


  getPqrsResumen(args: { range: string | [string, string] | string[] }) {
    return this.pqrsResumen(args) as Observable<PqrsResumen>;
  }

  getSeries(op: 'csat_series'|'nps_series'|'ces_series'|'pqrs_series'|'csat_pqrs_corr',
            args: { range: string | [string, string] | string[]; period: Periodo }) {
    return this.series(op, args) as Observable<any[]>;
  }
}
