import { Routes } from '@angular/router';

export const preguntaRoutes: Routes = [
    {
        path: '',
        children: [
            {
                path: '',
                pathMatch: 'full',
                loadComponent: () =>
                    import('./project/pregunta-list/pregunta-list').then((m) => m.PreguntaList),
            },
            // {
            //     path: 'preguntas/:id',
            //     loadComponent: () =>
            //         import('./project/encuesta-list/encuesta-list').then((m) => m.EncuestaList),
            // },
        ]
    }
];
