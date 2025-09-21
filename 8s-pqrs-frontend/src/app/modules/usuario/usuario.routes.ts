// src/app/modules/usuario/usuario.routes.ts
import { Routes } from '@angular/router';

export const usuarioRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/usuario-list/usuario-list').then((m) => m.UsuarioList),
            },
            {
                path: 'nuevo',
                loadComponent: () =>
                    import('./project/usuario-form/usuario-form').then((m) => m.UsuarioForm),
            },
            {
                path: 'editar/:id',
                loadComponent: () =>
                    import('./project/usuario-form/usuario-form').then((m) => m.UsuarioForm),
            }
        ]
    }
];
