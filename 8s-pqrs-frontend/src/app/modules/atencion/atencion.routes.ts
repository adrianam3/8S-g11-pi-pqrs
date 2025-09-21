import { Routes } from '@angular/router';

export const atencionRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/atencion-list/atencion-list').then((m) => m.AtencionList),
            },
        ]
    }
];
