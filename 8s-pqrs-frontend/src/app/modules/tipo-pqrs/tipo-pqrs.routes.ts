// src/app/modules/usuario/usuario.routes.ts
import { Routes } from '@angular/router';

export const tipoPqrsRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/tipo-pqrs-list/tipo-pqrs-list').then((m) => m.TipoPqrsList),
            },
        ]
    }
];
