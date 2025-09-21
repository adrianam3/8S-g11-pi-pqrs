import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api-service';


@Injectable({ providedIn: 'root' })
export class DashboardService {
  private base = `/controllers/dashboard.controller.php?op=`;

  constructor(private api: ApiService) {}

  kpis(params: any) { return this.api.get(`${this.base}kpis`, { params }); }
  cardsOverview(params: any) { return this.api.get(`${this.base}cards_overview`, { params }); }
  tendenciaSatisfaccionPqrs(params: any) { return this.api.get(`${this.base}tendencia_satisfaccion_pqrs`, { params }); }

  csatSegment(params: any) { return this.api.get(`${this.base}csat_segment`, { params }); }
  npsSegment(params: any) { return this.api.get(`${this.base}nps_segment`, { params }); }
  cesSegment(params: any) { return this.api.get(`${this.base}ces_segment`, { params }); }

  distribucionCalificaciones(params: any) { return this.api.get(`${this.base}distribucion_calificaciones`, { params }); }
  pqrsEstado(params: any) { return this.api.get(`${this.base}pqrs_estado`, { params }); }

  pqrsPorCategoria(params: any) { return this.api.get(`${this.base}pqrs_por_categoria`, { params }); }
  pqrsPorCategoriaPadre(params: any) { return this.api.get(`${this.base}pqrs_por_categoria_padre`, { params }); }

  tasaRespuesta(params: any) { return this.api.get(`${this.base}tasa_respuesta`, { params }); }
  encuestasResumen(params: any) { return this.api.get(`${this.base}encuestas_resumen`, { params }); }
  pqrsResumen(params: any) { return this.api.get(`${this.base}pqrs_resumen`, { params }); }
  encuestasConversion(params: any) { return this.api.get(`${this.base}encuestas_conversion`, { params }); }
}
