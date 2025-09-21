import { Routes } from '@angular/router';

export const categoriaPqrsRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/categoria-pqrs-list/categoria-pqrs-list').then((m) => m.CategoriaPqrsList),
            },
        ]
    }
];
