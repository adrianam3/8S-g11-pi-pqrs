export interface IEncuesta {
  idEncuesta: number;
  nombre: string;
  asuntoCorreo: string;
  remitenteNombre: string;
  scriptInicio?: string; // Puede ser opcional si permite null
  scriptFinal?: string;
  idCanal: number;
  activa: number; // O usar boolean si ya manejas true/false en lugar de 1/0
  fechaCreacion: string; // O Date, según cómo se reciba desde el backend
  fechaActualizacion: string;
  nombreCanal: string;
}
