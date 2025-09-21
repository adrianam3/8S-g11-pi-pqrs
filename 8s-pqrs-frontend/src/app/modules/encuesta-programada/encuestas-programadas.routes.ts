import { Routes } from '@angular/router';

export const encuestasProgramadasRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/encu-programada-list/encu-programada-list').then((m) => m.EncuProgramadaList),
            },
        ]
    }
];
