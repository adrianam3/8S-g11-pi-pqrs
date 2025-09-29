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
  pqrsTipoTot?: PqrsTipoTot; // <--- NUEVO
}

/* Tipos de los endpoints adicionales (opcional) */
export interface EncuestasTasa { enviadas: number; respondidas: number; tasa_pct: number; }
export interface PqrsResumen { total: number; abiertos: number; en_proceso: number; escalados: number; cerrados: number; }


// dashboard-service.ts (arriba del servicio o en un models.ts)
export interface EncuestaMatrizRow {
  idEncuesta: number;
  encuesta: string;
  canal: string;
  agencia: string;
  estadoEnvio: string;
  programadas: number;
  enviadas: number;
  respondidas: number;
  tasa_pct: number;
}

export interface PqrsTipoTot {
  peticion: number; queja: number; reclamo: number; sugerencia: number; total: number;
}



@Injectable({ providedIn: 'root' })
export class DashboardService {
  /** Nota: `this.base` ya incluye "?op=" para que agreguemos al final el nombre del op */
  private base = `/controllers/dashboard.controller.php?op=`;

  constructor(private api: ApiService) { }

  /* =======================
     Helpers de parámetros
     ======================= */

  /** Acepta range como tu array o string y devuelve un objeto plano listo para querystring */
  private buildParams(input: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string;[k: string]: any }) {
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

    if (input?.period) out.period = input.period;
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

  private kpis(params: any) { return this.api.get(`${this.base}kpis`, this.buildParams(params)); }
  private cardsOverview(params: any) { return this.api.get(`${this.base}cards_overview`, this.buildParams(params)); }
  private tendenciaSatisfaccionPqrs(p: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, this.buildParams(p)); }

  private csatSegment(p: any) { return this.api.get(`${this.base}csat_segment`, this.buildParams(p)); }
  private npsSegment(p: any) { return this.api.get(`${this.base}nps_segment`, this.buildParams(p)); }
  private cesSegment(p: any) { return this.api.get(`${this.base}ces_segment`, this.buildParams(p)); }

  private distribucionCalificaciones(p: any) { return this.api.get(`${this.base}distribucion_calificaciones`, this.buildParams(p)); }
  private pqrsEstado(p: any) { return this.api.get(`${this.base}pqrs_estado`, this.buildParams(p)); }
  private pqrsPorCategoria(p: any) { return this.api.get(`${this.base}pqrs_por_categoria`, this.buildParams(p)); }
  private pqrsPorCategoriaPadre(p: any) { return this.api.get(`${this.base}pqrs_por_categoria_padre`, this.buildParams(p)); }

  /* =======================
     NUEVOS endpoints (fijos)
     ======================= */

  /** ¡Clave!: pasamos los params *aplanados*, no como { params: {...} } */
  private encuestasTasa(p: any) { return this.api.get(`${this.base}encuestas_tasa`, this.buildParams(p)); }
  private pqrsResumen(p: any) { return this.api.get(`${this.base}pqrs_resumen`, this.buildParams(p)); }

  private series(op: 'csat_series' | 'nps_series' | 'ces_series' | 'pqrs_series' | 'csat_pqrs_corr', p: any) {
    return this.api.get(`${this.base}${op}`, this.buildParams(p));
  }

private pqrsTipoTotales(p: any) { 
  return this.api.get(`${this.base}pqrs_tipo_totales`, this.buildParams(p)); 
}



  /* =======================
     Punto único para la vista
     ======================= */

  getOverview(args: { range?: string | [string, string] | string[] | null; period?: Periodo; segment?: string;[k: string]: any }) {
    const params = this.buildParams(args);

    return forkJoin({
      kpis: this.kpis(params).pipe(catchError(() => of(null))),
      cards: this.cardsOverview(params).pipe(catchError(() => of(null))),
      tendencia: this.tendenciaSatisfaccionPqrs(params).pipe(catchError(() => of(null))),
      dist: this.distribucionCalificaciones(params).pipe(catchError(() => of(null))),
      csatSeg: this.csatSegment(params).pipe(catchError(() => of([]))),
      npsSeg: this.npsSegment(params).pipe(catchError(() => of([]))),
      cesSeg: this.cesSegment(params).pipe(catchError(() => of([]))),
      pqrsEstado: this.pqrsEstado(params).pipe(catchError(() => of([]))),
      pqrsCat: this.pqrsPorCategoria(params).pipe(catchError(() => of([]))),
      pqrsCatPadre: this.pqrsPorCategoriaPadre(params).pipe(catchError(() => of([]))),
      pqrsTipoTot: this.pqrsTipoTotales(params).pipe(catchError(() => of(null))),

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
        const npsPorSegmento = Array.isArray(r.npsSeg) ? r.npsSeg : [];
        const cesPorSegmento = Array.isArray(r.cesSeg) ? r.cesSeg : [];

        const pqrsPorEstado = Array.isArray(r.pqrsEstado) ? r.pqrsEstado : [];
        const pqrsPorCategoria = Array.isArray(r.pqrsCat) ? r.pqrsCat : [];
        const pqrsPorCategoriaPadre = Array.isArray(r.pqrsCatPadre) ? r.pqrsCatPadre : [];

        const pqrsTipoTot = r.pqrsTipoTot ?? { peticion:0, queja:0, reclamo:0, sugerencia:0, total:0 };

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
          pqrsPorCategoriaPadre, 
          pqrsTipoTot
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

  getSeries(op: 'csat_series' | 'nps_series' | 'ces_series' | 'pqrs_series' | 'csat_pqrs_corr',
    args: { range: string | [string, string] | string[]; period: Periodo }) {
    return this.series(op, args) as Observable<any[]>;
  }

  // 27/09/2025
  // 27/09/2025
  getEncuestasSegmentOverview(
    segment: 'canal' | 'agencia',
    ini: string,
    fin: string
  ) {
    // Puedes pasar range y buildParams añadirá fechaInicio/fechaFin
    const params = this.buildParams({ segment, range: `${ini},${fin}` });

    // Mantén el mismo patrón que el resto del servicio
    return this.api.get<any[]>(
      `${this.base}encuestas_segment_overview`,
      params
    );
  }

  // ... dentro de DashboardService
  getEncuestasMatriz(args: { range: string | [string, string] | string[] }): Observable<EncuestaMatrizRow[]> {
    const params = this.buildParams(args);
    // Si tu ApiService soporta genéricos:
    return this.api.get<EncuestaMatrizRow[]>(`${this.base}encuestas_matriz`, params);

    // Si NO soporta genéricos, forza el tipo:
    // return this.api.get(`${this.base}encuestas_matriz`, params) as Observable<EncuestaMatrizRow[]>;
  }


//7 28/09/2025
// NUEVO: NPS por Encuesta/Canal/Agencia/Asesor
getNpsResumenEntidad(args: { range: string | [string, string] | string[]; idEncuesta?: number }) {
  const params = this.buildParams(args);
  return this.api.get(`${this.base}nps_resumen_entidad`, params) as Observable<Array<{
    idEncuesta: number; encuesta: string; canal: string;
    idAsesor: number | null; asesor: string | null;
    idAgencia: number | null; agencia: string | null;
    total_nps: number; detractores: number; pasivos: number; promotores: number;
    detractores_pct: number; pasivos_pct: number; promotores_pct: number; nps: number;
  }>>;
}

// NUEVO: NPS – Detalle de Clientes
getNpsClientes(args: { range: string | [string, string] | string[] }) {
  const params = this.buildParams(args);
  return this.api.get(`${this.base}nps_clientes`, params) as Observable<Array<{
    idEncuesta: number; encuesta: string; canal: string;
    idAsesor: number | null; asesor: string | null;
    idAgencia: number | null; agencia: string | null;
    idCliente: number | null; cliente: string | null; celular: string | null; email: string | null;
    nps_val: number | null; clasificacion_nps: 'PROMOTOR' | 'PASIVO' | 'DETRACTOR' | 'SIN NPS';
  }>>;
}



  
}
