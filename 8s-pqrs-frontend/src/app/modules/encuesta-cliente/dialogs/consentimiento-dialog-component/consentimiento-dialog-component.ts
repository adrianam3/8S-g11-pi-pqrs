import { Component } from '@angular/core';
import { ConfirmationService, ConfirmEventType, MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { DynamicDialogRef, DynamicDialogConfig } from 'primeng/dynamicdialog';

@Component({
    selector: 'app-consentimiento-dialog-component',
    imports: [
        ButtonModule,
        ConfirmDialogModule,
    ],
    providers: [ConfirmationService],
    templateUrl: './consentimiento-dialog-component.html',
    styleUrl: './consentimiento-dialog-component.scss'
})
export class ConsentimientoDialogComponent {
    constructor(
        public ref: DynamicDialogRef,
        public config: DynamicDialogConfig,
        private confirmationService: ConfirmationService,
        private messageService: MessageService,
    ) { }

    aceptar() {
        this.ref.close({ aceptado: true });
    }

    rechazar() {
        this.confirmationService.confirm({
            header: 'Confirmación',
            message: '¿Está seguro de que no desea continuar con la encuesta? Esta acción es irreversible.',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, salir',
            rejectLabel: 'Cancelar',
            accept: () => {
                this.ref.close({ aceptado: false });
            },
            reject: (type: any) => {
                if (type === ConfirmEventType.REJECT) {
                    this.messageService.add({
                        severity: 'info',
                        summary: 'Cancelado',
                        detail: 'Puede seguir revisando la política antes de aceptar.'
                    });
                }
            }
        });
    }


    verPolitica(event: Event) {
        event.preventDefault(); // Previene redirección
        this.config.data?.verPolitica(); // Abre el segundo diálogo
    }

    // verPolitica() {
    //     this.config.data?.verPolitica?.(); // Llama la función pasada desde el componente padre
    // }
}
