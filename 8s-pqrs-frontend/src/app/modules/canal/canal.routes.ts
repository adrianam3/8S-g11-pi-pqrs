import { Routes } from '@angular/router';

export const canalRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/canal-list/canal-list').then((m) => m.CanalList),
            },
        ]
    }
];
