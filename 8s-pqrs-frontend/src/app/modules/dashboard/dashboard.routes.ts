// import { Routes } from '@angular/router';

// export const dashboardRoutes: Routes = [
//     {
//         path: '',
//         children: [
//             {
//                 path: '',
//                 pathMatch: 'full',
//                 loadComponent: () =>
//                     import('./project/dashboard/dashboard').then((m) => m.Dashboard),
//             },
//         ]
//     }
// ];


// import { Routes } from '@angular/router';
// import { DashboardComponent } from './project/dashboard/dashboard';

// export default [
//   { path: '', component: DashboardComponent, title: 'Dashboard' }
// ] as Routes;


import { Routes } from '@angular/router';
import { Dashboard } from './project/dashboard/dashboard';

export const dashboardRoutes: Routes = [
  { path: '', component: Dashboard, title: 'Dashboard' }
];
