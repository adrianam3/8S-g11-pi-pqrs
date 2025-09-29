// src/app/modules/usuario/usuario.routes.ts
import { Routes } from '@angular/router';

export const personaRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/persona-list/persona-list').then((m) => m.PersonaList),
            },
           
        ]
    }
];
