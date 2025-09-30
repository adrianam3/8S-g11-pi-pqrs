export interface IseguimientoPqrs {
  idSeguimiento: number;
  idPqrs: number;
  idUsuario?: number;
  usuarioLogin?: string;
  comentario?: string;
  cambioEstado?: number | null;
  nombreEstado?: string | null;
  adjuntosUrl?: string | string[] | null;  // puede venir string CSV
  fechaCreacion?: string;                  // ISO o 'YYYY-MM-DD HH:mm:ss'
  fechaActualizacion?: string;
  codigoPqrs?: string;
}
