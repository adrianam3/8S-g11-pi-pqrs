import { Routes } from '@angular/router';
import { AppLayout } from './app/layout/component/app.layout';
import { Dashboard } from './app/pages/dashboard/dashboard';
import { Landing } from './app/pages/landing/landing';
import { Notfound } from './app/pages/notfound/notfound';
import { Login } from '@/modules/usuario/login/login';
import { Inicio } from '@/modules/inicio/inicio';


// export const appRoutes: Routes = [
//     {
//         path: '',
//         component: AppLayout,
//         children: [
//             { path: '', component: Dashboard },
//             { path: 'home', component: Inicio },
//             // { path: 'uikit', loadChildren: () => import('./app/pages/uikit/uikit.routes') },
//             // { path: 'documentation', component: Documentation },
//             // { path: 'pages', loadChildren: () => import('./app/pages/pages.routes') }
//         ]
//     },
//     { path: 'landing', component: Landing },
//     { path: 'login', component: Login},
//     { path: 'notfound', component: Notfound },
//     { path: 'auth', loadChildren: () => import('./app/pages/auth/auth.routes') },
//     { path: '**', redirectTo: '/notfound' }
// ];

export const appRoutes: Routes = [
    // Rutas públicas (sin AppLayout)
    { path: 'login', component: Login },
    { path: 'landing', component: Landing },
    { path: 'notfound', component: Notfound },

    // Rutas protegidas (dentro del layout)
    {
        path: '',
        component: AppLayout,
        children: [
            { path: '', redirectTo: 'home', pathMatch: 'full' },
            { path: 'home', component: Inicio },
            // { path: 'dashboard', component: Dashboard },
            {
                path: 'usuario',
                loadChildren: () =>
                    import('./app/modules/usuario/usuario.routes').then((m) => m.usuarioRoutes)
            },
            {
                path: 'rol',
                loadChildren: () =>
                    import('./app/modules/rol/rol.routes').then((m) => m.rolRoutes)
            },
            {
                path: 'tipo-atencion',
                loadChildren: () =>
                    import('./app/modules/tipo-atencion/tipo-atencion.routes').then((m) => m.tipoAtencionRoutes)
            },
            {
                path: 'tipo-pqrs',
                loadChildren: () =>
                    import('./app/modules/tipo-pqrs/tipo-pqrs.routes').then((m) => m.tipoPqrsRoutes)
            },
            {
                path: 'categoria-pqrs',
                loadChildren: () =>
                    import('./app/modules/categoria-pqrs/categoria-pqrs.routes').then((m) => m.categoriaPqrsRoutes)
            },
            {
                path: 'encuestas',
                loadChildren: () =>
                    import('./app/modules/encuesta/encuesta.routes').then((m) => m.encuestaRoutes)
            },
            {
                path: 'preguntas',
                loadChildren: () =>
                    import('./app/modules/pregunta/pregunta.routes').then((m) => m.preguntaRoutes)
            },
            {
                path: 'clientes',
                loadChildren: () =>
                    import('./app/modules/cliente/cliente.routes').then((m) => m.clienteRoutes)
            },
            {
                path: 'pqrs',
                loadChildren: () =>
                    import('./app/modules/pqrs/pqrs.routes').then((m) => m.pqrsRoutes)
            },
            {
                path: 'canal',
                loadChildren: () =>
                    import('./app/modules/canal/canal.routes').then((m) => m.canalRoutes)
            },
            {
                path: 'dashboard',
                loadChildren: () =>
                    import('./app/modules/dashboard/dashboard.routes').then((m) => m.dashboardRoutes)
            },

            // { path: 'uikit', loadChildren: () => import('./app/pages/uikit/uikit.routes') },
            // { path: 'documentation', component: Documentation },
            // { path: 'pages', loadChildren: () => import('./app/pages/pages.routes') }
        ]
    },

    // Carga perezosa para módulo auth si lo tienes
    { path: 'auth', loadChildren: () => import('./app/pages/auth/auth.routes') },

    // Ruta comodín
    { path: '**', redirectTo: '/notfound' }
];
