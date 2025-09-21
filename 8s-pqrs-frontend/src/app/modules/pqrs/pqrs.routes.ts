import { Routes } from '@angular/router';

export const pqrsRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/pqrs-list/pqrs-list').then((m) => m.PqrsList),
            },
        ]
    }
];
