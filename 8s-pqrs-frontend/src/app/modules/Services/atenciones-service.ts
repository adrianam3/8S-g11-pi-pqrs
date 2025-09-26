import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiService } from './api-service';

export type ListFiltro = {
  limit?: number;
  offset?: number;
  soloActivas?: 1 | 0 | boolean | null;
  idCliente?: number | null;
  idAgencia?: number | null;
  fechaDesde?: string | null; // YYYY-MM-DD
  fechaHasta?: string | null; // YYYY-MM-DD
  q?: string | null;
  [k: string]: any;
};

export type UpsertBody = {
  idClienteErp?: string | null;
  cedula: string;
  nombres: string;
  apellidos: string;
  email?: string | null;
  telefono?: string | null;
  celular?: string | null;
  idAgencia: number;
  fechaAtencion: string;          // YYYY-MM-DD
  numeroDocumento: string;
  tipoDocumento: string;
  numeroFactura?: string | null;
  idCanal?: number | null;
  detalle?: string | null;
  cedulaAsesor?: string | null;
  programarAuto?: boolean;
  canalEnvio?: 'EMAIL'|'WHATSAPP'|'SMS'|'OTRO'|null;
  [k: string]: any;
};

@Injectable({ providedIn: 'root' })
export class AtencionesService {
  /** Igual que en DashboardService: base ya incluye ?op= */
  private base = 'controllers/atenciones.controller.php?op=';

  constructor(private api: ApiService) {}

  /** Aplana filtros (mismo criterio que en dashboard-service) */
  private flat(p: any = {}) {
    const out: any = {};
    Object.keys(p || {}).forEach(k => {
      const v = p[k];
      if (v !== undefined && v !== null && v !== '') out[k] = v;
    });
    return out;
  }

  // ===== GET =====
  listAll(filtros: ListFiltro = {}): Observable<any[]> {
    return this.api.get(`${this.base}todos`, this.flat(filtros)) as Observable<any[]>;
  }

  contar(filtros: ListFiltro = {}): Observable<{ total: number }> {
    return this.api.get(`${this.base}contar`, this.flat(filtros)) as Observable<{ total: number }>;
  }

  dependencias(idAtencion: number): Observable<{ [k: string]: number }> {
    return this.api.get(`${this.base}dependencias`, { idAtencion }) as Observable<{ [k: string]: number }>;
  }

  uno(idAtencion: number): Observable<any> {
    return this.api.get(`${this.base}uno`, { idAtencion }) as Observable<any>;
  }

  // ===== POST =====
  upsert(body: UpsertBody): Observable<any> {
    // ApiService.post envía JSON crudo; el controller ya lee php://input
    return this.api.post(`${this.base}upsert`, body) as Observable<any>;
  }

  insertar(body: {
    idCliente: number;
    idAgencia?: number | null;
    fechaAtencion: string;
    numeroDocumento: string;
    tipoDocumento: string;
    numeroFactura?: string | null;
    estado?: 0|1;
  }): Observable<any> {
    return this.api.post(`${this.base}insertar`, body) as Observable<any>;
  }

  actualizar(body: {
    idAtencion: number;
    idCliente: number;
    idAgencia?: number | null;
    fechaAtencion: string;
    numeroDocumento: string;
    tipoDocumento: string;
    numeroFactura?: string | null;
    estado?: 0|1;
  }): Observable<any> {
    return this.api.post(`${this.base}actualizar`, body) as Observable<any>;
  }

  eliminar(idAtencion: number): Observable<any> {
    return this.api.post(`${this.base}eliminar`, { idAtencion }) as Observable<any>;
  }

  activar(idAtencion: number): Observable<any> {
    return this.api.post(`${this.base}activar`, { idAtencion }) as Observable<any>;
  }

  desactivar(idAtencion: number): Observable<any> {
    return this.api.post(`${this.base}desactivar`, { idAtencion }) as Observable<any>;
  }

  // programarAuto(idAtencion: number, canalEnvio: 'EMAIL'|'WHATSAPP'|'SMS'|'OTRO'): Observable<any> {
  //   return this.api.post(`${this.base}programar_auto`, { idAtencion, canalEnvio }) as Observable<any>;
  //   }

    programarAuto(idAtencion: number, canalEnvio: 'EMAIL'|'WHATSAPP'|'SMS'|'OTRO') {
    return this.api.post(`${this.base}programar_auto`, { idAtencion, canalEnvio });
  }

validarAsesor(cedulaAsesor: string) {
  return this.api.post<{ success:boolean; exists:boolean; idAsesor?:number; nombres?:string; apellidos?:string; email?:string }>(
    'controllers/atenciones.controller.php?op=validar_asesor',
    { cedulaAsesor }
  );
}


    
}
