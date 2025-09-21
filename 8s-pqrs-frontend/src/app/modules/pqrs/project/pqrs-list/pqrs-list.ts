import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';

@Component({
    selector: 'app-pqrs-list',
    imports: [
        CommonModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        ConfirmDialogModule,
        InputTextModule,
        IconFieldModule,
        InputIconModule
    ],
    templateUrl: './pqrs-list.html',
    styleUrl: './pqrs-list.scss'
})
export class PqrsList implements OnInit {
    pqrs: any[] = [];
    loading = false;
    private pqrsApi = `controllers/pqrs.controller.php?op=`;

    constructor(private apiService: ApiService) { }

    ngOnInit(): void {
        this.cargarPqrs();
    }

    cargarPqrs(): void {
        this.loading = true;

        this.apiService.get<any[]>(this.pqrsApi + 'todos').subscribe(
            (data) => {
                this.pqrs = data;
                this.loading = false;
            },
            (error) => {
                console.error('Error al cargar los PQRS', error);
                // this.apiService.showToast('error', 'Error al obtener los PQRS.', 'Error');
                this.loading = false;
            }
        );
    }

    onGlobalFilter(dt: any, event: any): void {
        dt.filterGlobal((event.target as HTMLInputElement).value, 'contains');
    }

    clearFilters(dt: any): void {
        dt.clear();
    }

    editarPqrs(row: any): void {
        console.log('Editar PQR:', row);
        // Navegar a formulario o abrir modal
    }

    confirmarEliminar(row: any): void {
        console.log('Eliminar PQR:', row);
        // Confirmación y acción lógica (cambio estadoRegistro a 0)
    }
}
