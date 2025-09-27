import { Routes } from '@angular/router';

export const encuestaClienteRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/encuesta-cliente-list/encuesta-cliente-list').then((m) => m.EncuestaClienteList),
            },
            {
                path: 'enc/:id',
                loadComponent: () =>
                    import('./project/encuesta-cliente-list/encuesta-cliente-list').then((m) => m.EncuestaClienteList),
            },
        ]
    }
];
