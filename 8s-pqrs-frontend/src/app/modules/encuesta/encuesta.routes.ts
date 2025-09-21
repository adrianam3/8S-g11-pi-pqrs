import { Routes } from '@angular/router';

export const encuestaRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/encuesta-list/encuesta-list').then((m) => m.EncuestaList),
            },
            // {
            //     path: 'preguntas/:id',
            //     loadComponent: () =>
            //         import('./project/encuesta-list/encuesta-list').then((m) => m.EncuestaList),
            // },
            {
                path: 'preguntas/:id',
                loadChildren: () =>
                    import('../pregunta/pregunta.routes').then((m) => m.preguntaRoutes),
            },
        ]
    }
];
