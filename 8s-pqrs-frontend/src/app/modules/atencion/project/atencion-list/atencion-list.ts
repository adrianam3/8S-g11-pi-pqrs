import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';
import { MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';

@Component({
    selector: 'app-atencion-list',
    imports: [
        CommonModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        ConfirmDialogModule,
        InputTextModule,
        IconFieldModule,
        InputIconModule,
    ],
    templateUrl: './atencion-list.html',
    styleUrl: './atencion-list.scss'
})
export class AtencionList implements OnInit {
    atencionesAll: any[] = [];
    loading: boolean = true;

    constructor(
        private apiService: ApiService,
        private messageService: MessageService
    ) { }

    ngOnInit(): void {
        this.cargarAtenciones();
    }

    cargarAtenciones(): void {
        this.loading = true;

        // this.apiService.get('controllers/atencion.controller.php?op=listar')
        //     .then(response => {
        //         response.subscribe({
        //             next: (data: any[]) => {
        //                 this.atencionesAll = data;
        //                 this.loading = false;
        //             },
        //             error: (err) => {
        //                 console.error('Error al obtener atenciones:', err);
        //                 this.messageService.add({ severity: 'error', summary: 'Error', detail: 'No se pudo cargar las atenciones' });
                        this.loading = false;
        //             }
        //         });
        //     });
    }

    onGlobalFilter(table: any, event: Event) {
        const input = (event.target as HTMLInputElement).value;
        table.filterGlobal(input, 'contains');
    }

    clearFilter() {
        this.atencionesAll = [...this.atencionesAll]; // Forzar redibujado si es necesario
    }

    verDetalle(rowData: any) {
        console.log('Detalle de atención:', rowData);
        // Aquí puedes abrir un modal, navegar a otra ruta, etc.
    }

    editarAtencion(rowData: any) {
        console.log('Editar atención:', rowData);
        // Redirige al formulario de edición o abre un modal
    }

    eliminarAtencion(rowData: any) {
        console.log('Eliminar atención (soft-delete):', rowData);
        // Lógica para marcar como estado = 0 o enviar petición a controlador
    }
}

