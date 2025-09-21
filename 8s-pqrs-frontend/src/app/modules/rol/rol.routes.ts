// src/app/modules/usuario/usuario.routes.ts
import { Routes } from '@angular/router';

export const rolRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/rol-list/rol-list').then((m) => m.RolList),
            },
        ]
    }
];
