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
    selector: 'app-canal-list',
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
    templateUrl: './canal-list.html',
    styleUrl: './canal-list.scss'
})
export class CanalList implements OnInit {
    canalesAll: any[] = [];
    loading: boolean = false;
    private canalesApi = `controllers/canales.controller.php?op=`;

      constructor(
        private apiService: ApiService,
    ) { }

    ngOnInit(): void {
        this.loadCanales();

    }

    private loadCanales() {
        this.loading = true;

        this.apiService.get<any[]>(this.canalesApi + 'todos').subscribe(
            (data) => {
                this.canalesAll = data;
                this.loading = false;
            },
            (error) => {
                console.error('Error al cargar canales', error);
                this.apiService.showToast('error', 'Error al obtener los canales.', 'Error');
                this.loading = false;
            }
        );
    }

    onGlobalFilter(dt: any, event: any) {
        dt.filterGlobal(event.target.value, 'contains');
    }

    clearFilters(dt: any) {
        dt.clear();
    }

    editarCanal(row: any) {
        console.log('Editar:', row);
    }

    confirmarEliminar(row: any) {
        console.log('Eliminar:', row);
    }
}
