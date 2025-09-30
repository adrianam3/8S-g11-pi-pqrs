import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { DatePickerModule } from 'primeng/datepicker';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { SelectModule } from 'primeng/select';
import { Table, TableModule } from 'primeng/table';
import { TagModule } from 'primeng/tag';
import { ToastModule } from 'primeng/toast';
import { TooltipModule } from 'primeng/tooltip';
import { Router } from '@angular/router';
import { ConfirmationService, MessageService } from 'primeng/api';

@Component({
    selector: 'app-pqrs-list',
    imports: [
        CommonModule,
        FormsModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        ConfirmDialogModule,
        InputTextModule,
        IconFieldModule,
        InputIconModule,
        SelectModule,
        TagModule,
        TooltipModule,
        ProgressSpinnerModule,
        DatePickerModule,
    ],
    providers: [ConfirmationService, MessageService],
    templateUrl: './pqrs-list.html',
    styleUrl: './pqrs-list.scss'
})
export class PqrsList implements OnInit {
    public idUsuario: number = 2; // string = localStorage.getItem('idUsuario') || '';
    public idRol: number = 2; //localStorage.getItem('idRol') || '';
    pqrs: any[] = [];
    loading = false;
    private pqrsApi = `controllers/pqrs.controller.php?op=`;

    constructor(
        private apiService: ApiService,
        private router: Router,
        private confirmationService: ConfirmationService,
        private messageService: MessageService,

    ) { }

    ngOnInit(): void {
        this.cargarPqrs();
    }

    cargarPqrs(): void {
        this.loading = true;

        this.apiService.get<any[]>(this.pqrsApi + 'todos').subscribe(
            (data) => {
                this.pqrs = data;
                this.loading = false;
                console.log('PQRS cargados:', this.pqrs);
            },
            (error) => {
                console.error('Error al cargar los PQRS', error);
                this.loading = false;
            }
        );
    }

    tiposPqrsOptions = [
        { label: '—', value: null },
        { label: 'Queja', value: 'Queja' },
        { label: 'Reclamo', value: 'Reclamo' },
        { label: 'Petición', value: 'Petición' },
    ];

    estadosOptions = [
        { label: '—', value: null },
        { label: 'ABIERTO', value: 'ABIERTO' },
        { label: 'EN PROGRESO', value: 'EN PROGRESO' },
        { label: 'CERRADO', value: 'CERRADO' },
    ];

    estadoRegistroOptions = [
        { label: '—', value: null },
        { label: 'Activo', value: 1 },
        { label: 'Inactivo', value: 0 },
    ];

    // handlers existentes:
    clearFilters(dt: Table) { dt.clear(); }
    onGlobalFilter(dt: Table, e: Event) {
        dt.filterGlobal((e.target as HTMLInputElement).value, 'contains');
    }
    verSeguimientos(rowData: any) {
        this.router.navigate(['/pqrs/seguimiento', rowData.idPqrs]);
    }


    confirmarInicioSegimiento(rowData: any): void {
        const pqrs = rowData;
        const idPqrs = pqrs?.idPqrs;

        this.confirmationService.confirm({
            header: 'Confirmar inicio de seguimiento',
            message: 'Se asignará el PQR al responsable inicial. ¿Deseas continuar?',
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí',
            rejectLabel: 'No',
            accept: () => this.iniciarSeguimiento(idPqrs)
        });
    }

    // Iniciar Seguimiento
    iniciarSeguimiento(idPqrs: any): void {
        // responsable_activo_pqrs
        if (!idPqrs) {
            this.apiService.showToast('error', 'Error', 'ID de PQR inválido.');
            return;
        }
        this.apiService.get<any>(`controllers/pqrs_responsables.controller.php?op=responsable_activo_pqrs&idPqrs=${idPqrs}`).subscribe(
            (resp) => {
                const responsable = resp?.data;
                if (responsable) {
                    this.insertarSeguimiento(idPqrs, responsable.idResponsable);
                }
            });
    }

    puedeIniciar(row: any): boolean {
        const activo = row?.idEstado === 1;
        return activo;
    }

    async insertarSeguimiento(idPqrs: number, idResponsable: number): Promise<void> {
        const fd = new FormData();
        fd.append('idPqrs', String(idPqrs));
        fd.append('comentario', String('Inicio de seguimiento'));
        fd.append('idUsuario', String(idResponsable));
        // fd.append('idUsuario', String(this.idUsuario));

        const resp = await (
            await this.apiService.post('controllers/seguimientospqrs.controller.php?op=insertar', fd)
        ).toPromise();

        if ((resp as any)?.success === false) {
            this.messageService.add({
                severity: 'warn',
                summary: 'Seguimiento',
                detail: (resp as any)?.message || 'No se pudo agregar el seguimiento.'
            });
            return;
        } else {
            this.messageService.add({
                severity: 'success',
                summary: 'Seguimiento',
                detail: 'Inicio de Seguimiento Correcto.'
            });
        }



    }

}
