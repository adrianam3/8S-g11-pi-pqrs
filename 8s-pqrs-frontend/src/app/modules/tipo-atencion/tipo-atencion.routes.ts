// src/app/modules/usuario/usuario.routes.ts
import { Routes } from '@angular/router';

export const tipoAtencionRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/tipo-atencion-list/tipo-atencion-list').then((m) => m.TipoAtencionList),
            },
        ]
    }
];
