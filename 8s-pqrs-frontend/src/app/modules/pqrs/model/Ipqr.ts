export interface IPqr {
    idPqrs: number;
    idTipo?: number;
    idCategoria?: number;
    idCanal?: number;
    idEstado?: number;
    idAgencia?: number;
    idCliente?: number;
    idEncuesta?: number;
    idProgEncuesta?: number;
    idAtencion?: number;

    nombreTipo?: string;
    nombreCategoria?: string;
    nombreCanal?: string;
    nombreEstado?: string;
    nombreAgencia?: string;

    cedula?: string;
    nombres?: string;
    apellidos?: string;
    nombreCliente?: string;
    emailCliente?: string;

    nombreEncuesta?: string;
    estadoProgEncuesta?: string;

    asunto?: string;
    detalle?: string;

    direccion?: string;
    telefono?: string;
    ciudad?: string;
    idEncuestaProgramada?: number;
    codigo?: string;

    // agrega aquí cualquier otra columna de p.* que estés usando
}
