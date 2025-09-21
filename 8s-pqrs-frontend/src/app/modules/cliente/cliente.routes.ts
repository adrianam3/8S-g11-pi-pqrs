import { Routes } from '@angular/router';

export const clienteRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/cliente-list/cliente-list').then((m) => m.ClienteList),
            },
             {
                path: 'atenciones',
                loadChildren: () =>
                    import('../atencion/atencion.routes').then((m) => m.atencionRoutes),
            },
            {
                path: 'enc-programadas',
                loadChildren: () =>
                    import('../encuesta-programada/encuestas-programadas.routes').then((m) => m.encuestasProgramadasRoutes),
            },
        ]
    }
];
