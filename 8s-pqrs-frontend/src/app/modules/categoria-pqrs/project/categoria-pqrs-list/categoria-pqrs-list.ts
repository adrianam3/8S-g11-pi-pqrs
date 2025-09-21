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
    selector: 'app-categoria-pqrs-list',
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
    templateUrl: './categoria-pqrs-list.html',
    styleUrl: './categoria-pqrs-list.scss'
})
export class CategoriaPqrsList implements OnInit {
    categorias: any[] = [];
    loading = false;
    private categoriasApi = `controllers/categoriaspqrs.controller.php?op=`;

    constructor(private apiService: ApiService) { }

    ngOnInit(): void {
        this.cargarCategorias();
    }

    cargarCategorias(): void {
        this.loading = true;

        this.apiService.get<any[]>(this.categoriasApi + 'todos').subscribe(
            (data) => {
                this.categorias = data;
                this.loading = false;
                console.log('data', data);
            },
            (error) => {
                console.error('Error al cargar los PQRS', error);
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

    editarCategoria(row: any): void {
        console.log('Editar categoría:', row);
        // lógica para redirigir a edición o mostrar modal
    }

    confirmarEliminar(row: any): void {
        console.log('Eliminar categoría:', row);
        // lógica para eliminación (confirmación y cambio de estado)
    }
}
