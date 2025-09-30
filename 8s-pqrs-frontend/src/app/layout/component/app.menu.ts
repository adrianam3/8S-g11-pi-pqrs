import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { MenuItem } from 'primeng/api';
import { AppMenuitem } from './app.menuitem';

@Component({
    selector: 'app-menu',
    standalone: true,
    imports: [CommonModule, AppMenuitem, RouterModule],
    template: `<ul class="layout-menu">
        <ng-container *ngFor="let item of model; let i = index">
            <li app-menuitem *ngIf="!item.separator" [item]="item" [index]="i" [root]="true"></li>
            <li *ngIf="item.separator" class="menu-separator"></li>
        </ng-container>
    </ul> `
})
export class AppMenu {
    model: MenuItem[] = [];

    ngOnInit() {
        this.model = [
            {
                label: 'Home',
                items: [
                    { label: 'Inicio', icon: 'pi pi-fw pi-home', routerLink: ['/'] },
                    { label: 'Dashboard', icon: 'pi pi-fw pi-chart-bar', routerLink: ['/dashboard'] },
                ]
            },
            {
                label: 'Gestión de Usuarios',
                items: [
                    { label: 'Roles', icon: 'pi pi-fw pi-receipt', routerLink: ['/rol'] },
                    { label: 'Usuarios', icon: 'pi pi-fw pi-users', routerLink: ['/usuario'] },
                     { label: 'Personas', icon: 'pi pi-fw pi-users', routerLink: ['/persona'] },
                ]
            },
            {
                label: 'Gestión de Encuestas',
                items: [
                    {
                        label: 'Encuestas',
                        icon: 'pi pi-fw pi-clipboard',
                        routerLink: ['/encuestas']
                    },
                    {
                        label: 'Clientes',
                        icon: 'pi pi-fw pi-user-plus',
                        routerLink: ['/clientes']
                    },
                ]
            },
            {
                label: 'Gestión de PQRS',
                items: [
                    {
                        label: 'Categorías de PQRS',
                        icon: 'pi pi-fw pi-folder-open',
                        routerLink: ['/categoria-pqrs']
                    },
                    {
                        label: 'Tipos de PQRS',
                        icon: 'pi pi-fw pi-file-check',
                        routerLink: ['/tipo-pqrs']
                    },
                    // {
                    //     label: 'Registrar PQRS',
                    //     icon: 'pi pi-fw pi-plus',
                    //     routerLink: ['/registrarPqrs']
                    // },
                    // {
                    //     label: 'Clasificar PQRS',
                    //     icon: 'pi pi-fw pi-filter',
                    //     routerLink: ['/clasificarPqrs']
                    // },
                    // {
                    //     label: 'Asignar PQRS',
                    //     icon: 'pi pi-fw pi-check',
                    //     routerLink: ['/asignarPqrs']
                    // },
                    {
                        label: 'PQRS',
                        icon: 'pi pi-fw pi-list',
                        routerLink: ['/pqrs']
                    },
                    // {
                    //     label: 'Seguimiento y Cierre',
                    //     icon: 'pi pi-fw pi-check-circle',
                    //     routerLink: ['/seguimiento']
                    // },
                    {
                        label: 'Canales',
                        icon: 'pi pi-fw pi-check-circle',
                        routerLink: ['/canal']
                    }
                ]
            },
            {
                label: 'Gestión de Clientes',
                items: [
                    {
                        label: 'Clientes',
                        icon: 'pi pi-fw pi-address-book',
                        routerLink: ['/clientes']
                    }
                ]
            }
        ];
    }
}
