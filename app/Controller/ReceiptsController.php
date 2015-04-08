<?php

App::uses('AppController', 'Controller');
App::uses('CakeEvent', 'Event');

/**
 * Receipts Controller
 *
 * @property Receipt $Receipt
 * @property PaginatorComponent $Paginator
 */
class ReceiptsController extends AppController {

    /**
     * Components
     *
     * @var array
     */
    public $components = array('Paginator', 'RequestHandler');

    /**
     * index method
     *
     * @return void
     */
    public function index() {
        $this->Receipt->recursive = 0;
        $this->Paginator->settings = $this->paginate + array(
            'conditions' => array(
                'Receipt.condo_id' => $this->Session->read('Condo.ViewID')),
        );
        $this->setFilter(array('Receipt.document', 'Client.name', 'ReceiptStatus.name', 'ReceiptPaymentType.name', 'Receipt.total_amount'));
        $this->set('receipts', $this->paginate());
        $this->Session->delete('Condo.Receipt');
    }

    /**
     * view method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function view($id = null) {
        $this->Receipt->recursive = 2;
        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }
        $options = array('conditions' => array('Receipt.' . $this->Receipt->primaryKey => $id, 'Receipt.condo_id' => $this->Session->read('Condo.ViewID')));
        $receipt = $this->Receipt->find('first', $options);
        $this->set(compact('receipt'));
        $this->Session->write('Condo.Receipt.ViewID', $id);
        $this->Session->write('Condo.Receipt.ViewName', $receipt['Receipt']['document']);
    }

    

    /**
     * print_receipt method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function print_receipt($id) {
        $this->Receipt->recursive = 2;
        if (!$this->Receipt->exists($id)) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }
        
        $event = new CakeEvent('Phkondo.Receipt.print', $this, array(
            'id' => $id,
        ));
        $this->getEventManager()->dispatch($event);
    }

    /**
     * add method
     *
     * @return void
     */
    public function add() {
        if ($this->request->is('post') && isset($this->request->data['Receipt']['change_filter']) && $this->request->data['Receipt']['change_filter'] != '1') {
            $this->Receipt->create();
            $this->Receipt->Client->id = $this->request->data['Receipt']['client_id'];
            $this->Receipt->Client->order = 'Client.name';
            $this->request->data['Receipt']['address'] = $this->Receipt->Client->field('address');
            $number = $this->_getNextReceiptIndex($this->Session->read('Condo.ViewID'));
            $this->request->data['Receipt']['document'] = $this->Session->read('Condo.ViewID') . Date('Y') . '-' . sprintf('%06d', $number);
            $this->request->data['Receipt']['document_date'] = date('Y-m-d');
            if ($this->Receipt->save($this->request->data)) {
                $this->_setReceiptIndex($this->Session->read('Condo.ViewID'), $number);
                $this->Session->setFlash(__('The receipt has been saved'), 'flash/success');
                $this->redirect(array('action' => 'view', $this->Receipt->id));
            } else {
                $this->Session->setFlash(__('The receipt could not be saved. Please, try again.'), 'flash/error');
            }
        }
        $condos = $this->Receipt->Condo->find('list', array('conditions' => array('Condo.id' => $this->Session->read('Condo.ViewID'))));
        $fractions = $this->Receipt->Condo->Fraction->find('list', array('conditions' => array('Fraction.condo_id' => $this->Session->read('Condo.ViewID'))));

        If (!isset($this->request->data['Receipt']['fraction_id'])) {
            $fractionsForClients = $fractions;
            reset($fractionsForClients);
            $firstFraction = key($fractionsForClients);
        } else {
            $firstFraction = $this->request->data['Receipt']['fraction_id'];
        }
        $fractionsForClients = $this->Receipt->Fraction->find('all', array('conditions' => array('Fraction.id' => $firstFraction)));
        $clients = $this->Receipt->Client->find('list', array('order' => 'Client.name', 'conditions' => array('Client.id' => Set::extract('/Entity/id', $fractionsForClients))));
        $receiptStatuses = $this->Receipt->ReceiptStatus->find('list', array('conditions' => array('id' => array('1', '2'), 'active' => '1')));
        $receiptPaymentTypes = $this->Receipt->ReceiptPaymentType->find('list', array('conditions' => array('active' => '1')));
        unset($this->request->data['Receipt']['change_filter']);
        $this->set(compact('condos', 'fractions', 'clients', 'receiptStatuses', 'receiptPaymentTypes'));
    }

    /**
     * add_notes method
     *
     * @return void
     */
    public function add_notes($id) {

        $this->Receipt->id = $id;
        if ($this->Receipt->field('id') != $id) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }

        if ($this->request->is('post') && isset($this->request->data['Note'])) {
            foreach ($this->request->data['Note'] as $key => $note) {

                if (isset($note['check'])) {

                    $noteOk = $this->Receipt->Note->find('count', array('conditions' => array('Note.id' => $key, 'Note.receipt_id' => '')));

                    if ($noteOk == 0) {
                        $this->Session->setFlash(__('The notes could not be saved. Please, try again.'), 'flash/error');
                        return;
                    }
                    $this->Receipt->Note->id = $key;
                    $this->Receipt->Note->saveField('receipt_id', $id, array('callbacks' => false));
                    $this->Receipt->Note->saveField('pending_amount', '0', array('callbacks' => false));
                }
            }
            //$total = $this->Receipt->Note->find('first', array('fields' => array('sum(Note.amount) as total'), 'conditions' => array('Note.receipt_id' => $id)));
            $this->_setReceiptAmount($id);
            $this->redirect(array('action' => 'view', $id));
        }

        $fractions = $this->Receipt->Condo->Fraction->find('all', array('conditions' => array('Fraction.id' => $this->Receipt->field('fraction_id'))));
        $notes = $this->Receipt->Note->find('all', array('conditions' => array('Note.fraction_id' => Set::extract('/Fraction/id', $fractions), 'Note.entity_id' => $this->Receipt->field('client_id'), 'Note.note_status_id' => array(1, 2), 'Note.receipt_id' => '')));

        $receiptAmount = $this->Receipt->field('total_amount');
        $receiptId = $this->Receipt->field('document');
        $this->set(compact('notes', 'receiptAmount', 'receiptId','id'));
    }

    /**
     * remove_note method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function remove_note($id = null) {
        if (!$this->Receipt->Note->exists($id)) {
             $this->Session->setFlash(__('Invalid note'), 'flash/error');
            $this->redirect(array('action' => 'index'));
           
        }
        $this->Receipt->Note->id = $id;
        $receipt = $this->Receipt->Note->field('receipt_id');
        if ($receipt != $this->Session->read('Condo.Receipt.ViewID')) {
            $this->Session->setFlash(__('Invalid note'), 'flash/error');
            $this->redirect(array('action' => 'edit', $receipt));
        }
        $this->Receipt->Note->id = $id;
        $this->Receipt->Note->saveField('receipt_id', null, array('callbacks' => false));
        $restoreAmount = $this->Receipt->Note->field('amount');
        $this->Receipt->Note->saveField('pending_amount', $restoreAmount, array('callbacks' => false));
        $this->_setReceiptAmount($receipt);
        $this->redirect(array('action' => 'view', $receipt));
    }

    /**
     * pay receipt method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function pay_receipt($id = null) {

        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }

        if (!$this->Receipt->payable($id)) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'view', $id));
        }

        if (!empty($this->request->data) && ($this->request->is('post') || $this->request->is('put'))) {
            if ($this->Receipt->save($this->request->data)) {
                $this->close($id);
                $this->Session->setFlash(__('The receipt has been saved'), 'flash/success');
                $this->redirect(array('action' => 'view', $id));
            } else {
                $this->Session->setFlash(__('The receipt could not be saved. Please, try again.'), 'flash/error');
            }
        } else {
            $options = array('conditions' => array('Receipt.' . $this->Receipt->primaryKey => $id, 'OR' => array('Receipt.receipt_status_id' => array('2'))));
            $this->request->data = $this->Receipt->find('first', $options);
            if (empty($this->request->data)) {
                 $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
            }
        }



        $condos = $this->Receipt->Condo->find('list', array('conditions' => array('id' => $this->Session->read('Condo.ViewID'))));
        $clients = $this->Receipt->Client->find('list', array('order' => 'Client.name', 'conditions' => array('id' => $this->request->data['Receipt']['client_id'])));
        $fractions = $this->Receipt->Fraction->find('list', array('conditions' => array('id' => $this->request->data['Receipt']['fraction_id'])));
        $receiptStatuses = $this->Receipt->ReceiptStatus->find('list', array('conditions' => array('id' => array('3'), 'active' => '1')));
        $receiptPaymentTypes = $this->Receipt->ReceiptPaymentType->find('list', array('conditions' => array('active' => '1')));
        $this->set(compact('condos', 'fractions', 'clients', 'receiptStatuses', 'receiptPaymentTypes'));
        $this->Session->write('Condo.Receipt.ViewID', $id);
        $this->Session->write('Condo.Receipt.ViewName', $this->request->data['Receipt']['document']);
    }

    /**
     * edit method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function edit($id = null) {
        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }

        if (!$this->Receipt->editable($id)) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'view', $id));
        }

        if ($this->request->is('post') || $this->request->is('put')) {
            if ($this->Receipt->save($this->request->data)) {
                $this->Session->setFlash(__('The receipt has been saved'), 'flash/success');
                $this->redirect(array('action' => 'view', $id));
            } else {
                $this->Session->setFlash(__('The receipt could not be saved. Please, try again.'), 'flash/error');
            }
        } else {
            $options = array('conditions' => array('Receipt.' . $this->Receipt->primaryKey => $id, 'OR' => array('Receipt.receipt_status_id' => array('1', '2'))));
            $this->request->data = $this->Receipt->find('first', $options);
            if (empty($this->request->data)) {
                $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
            }
        }



        $condos = $this->Receipt->Condo->find('list', array('conditions' => array('id' => $this->Session->read('Condo.ViewID'))));
        $fractions = $this->Receipt->Fraction->find('list', array('conditions' => array('id' => $this->request->data['Receipt']['fraction_id'])));
        $clients = $this->Receipt->Client->find('list', array('order' => 'Client.name', 'conditions' => array('id' => $this->request->data['Receipt']['client_id'])));
        $receiptStatuses = $this->Receipt->ReceiptStatus->find('list', array('conditions' => array('id' => array('1', '2'), 'active' => '1')));
        $receiptPaymentTypes = $this->Receipt->ReceiptPaymentType->find('list', array('conditions' => array('active' => '1')));
        $this->set(compact('condos', 'fractions', 'clients', 'receiptStatuses', 'receiptPaymentTypes'));
        $this->Session->write('Condo.Receipt.ViewID', $id);
        $this->Session->write('Condo.Receipt.ViewName', $this->request->data['Receipt']['document']);
    }

    /**
     * delete method
     *
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     * @param string $id
     * @return void
     */
    public function delete($id = null) {
        if (!$this->request->is('post')) {
            throw new MethodNotAllowedException();
        }
        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }


        if (!$this->Receipt->deletable($id)) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'view', $id));
        }
        $this->_removeFromNote($id);
        if ($this->Receipt->delete()) {

            $this->Session->setFlash(__('Receipt deleted'), 'flash/success');
            $this->redirect(array('action' => 'index'));
        }
        $this->Session->setFlash(__('Receipt can not be deleted'), 'flash/error');
        $this->redirect(array('action' => 'view', $id));
    }


    /**
     * close method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function close($id = null) {
        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }


        if (!$this->Receipt->closeable()) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'view', $id));
        }

        $this->Receipt->saveField('payment_user_id', AuthComponent::user('id'));
        $this->_setNotesStatus($id);
        $this->redirect(array('action' => 'view', $id));
    }


    /**
     * cancel method
     *
     * @throws NotFoundException
     * @param string $id
     * @return void
     */
    public function cancel($id = null) {
        if (!$this->Receipt->exists($id)) {
             $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'index'));
        }


        if (!$this->Receipt->cancelable($id)) {
            $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
            $this->redirect(array('action' => 'view', $id));
        }

        if (!empty($this->request->data) && ($this->request->is('post') || $this->request->is('put'))) {
            if ($this->Receipt->save($this->request->data)) {
                $this->Receipt->saveField('cancel_user_id', AuthComponent::user('id'));
                $this->_transferNotes($id);
                $this->_removeFromNote($id);
                $this->Session->setFlash(__('The receipt has been saved'), 'flash/success');
                $this->redirect(array('action' => 'view', $id));
            } else {
                $this->Session->setFlash(__('The receipt could not be saved. Please, try again.'), 'flash/error');
            }
        } else {
            $options = array('conditions' => array('Receipt.id' => $id));
            $this->request->data = $this->Receipt->find('first', $options);
            if (empty($this->request->data)) {
                $this->Session->setFlash(__('Invalid receipt'), 'flash/error');
                $this->redirect(array('action' => 'index'));
            }
        }

        $condos = $this->Receipt->Condo->find('list', array('conditions' => array('id' => $this->request->data['Receipt']['condo_id'])));
        $fractions = $this->Receipt->Fraction->find('list', array('conditions' => array('id' => $this->request->data['Receipt']['fraction_id'])));
        $clients = $this->Receipt->Client->find('list', array('order' => 'Client.name', 'conditions' => array('id' => $this->request->data['Receipt']['client_id'])));
        $receiptStatuses = $this->Receipt->ReceiptStatus->find('list', array('conditions' => array('id' => array('4'), 'active' => '1')));
        $this->set(compact('condos', 'fractions', 'clients', 'receiptStatuses', 'receiptPaymentTypes'));
        $this->Session->write('Condo.Receipt.ViewID', $id);
        $this->Session->write('Condo.Receipt.ViewName', $this->request->data['Receipt']['document']);
    }

    private function _transferNotes($id) {
        $options = array('conditions' => array('Note.receipt_id' => $id));
        $notes = $this->Receipt->Note->find('all', $options);
        if (count($notes)==0) {
            return true;
        }
        foreach ($notes as $key => $note) {
            unset($notes[$key]['Note']['id']);
            $notes[$key]['Note']['note_status_id'] = 4;
            $receiptNotes[]['ReceiptNote'] = $notes[$key]['Note'];
        }
        if ($this->Receipt->ReceiptNote->saveAll($receiptNotes)) {
            return true;
        }
        return false;
    }

    private function _removeFromNote($id) {
        return $this->Receipt->Note->updateAll(array('Note.receipt_id' => null, 'Note.note_status_id' => '1', 'Note.pending_amount' => 'Note.amount', 'Note.payment_date' => null), array('Note.receipt_id' => $id));
    }

    private function _setNotesStatus($id) {
        return $this->Receipt->Note->updateAll(array('Note.note_status_id' => '3', 'Note.pending_amount' => '0', 'Note.payment_date' => 'Receipt.payment_date'), array('Note.receipt_id' => $id));
    }

    private function _setReceiptAmount($id) {
        $this->Receipt->recursive = 0;
        $totalDebit = $this->Receipt->Note->find('first', array('fields' =>
            array('SUM(Note.amount) AS total'),
            'conditions' => array('Note.receipt_id' => $id, 'Note.note_type_id' => '2')
                )
        );
        $totalCredit = $this->Receipt->Note->find('first', array('fields' =>
            array('SUM(Note.amount) AS total'),
            'conditions' => array('Note.receipt_id' => $id, 'Note.note_type_id' => '1')
                )
        );
        $total = $totalDebit[0]['total'] - $totalCredit[0]['total'];
        $this->Receipt->id = $id;
        $this->Receipt->saveField('total_amount', $total);
    }

    private function _getNextReceiptIndex($id) {
        $this->loadModel("ReceiptCounters");
        $index = $this->ReceiptCounters->find('first', array('conditions' => array('condo_id' => $id)));

        if (!isset($index['ReceiptCounters']['counter'])) {
            $this->ReceiptCounters->create();
            $this->ReceiptCounters->save(array('ReceiptCounters' => array('condo_id' => $id, 'counter' => 1)));
            return $index = 1;
        }

        return $index['ReceiptCounters']['counter'] + 1;
    }

    private function _setReceiptIndex($condo_id, $index) {
        $this->loadModel("ReceiptCounters");
        $rcpIndex = $this->ReceiptCounters->find('first', array('conditions' => array('condo_id' => $condo_id)));
        if (!isset($rcpIndex['ReceiptCounters']['counter'])) {
            $this->ReceiptCounters->create();
            $this->ReceiptCounters->save(array('ReceiptCounters' => array('condo_id' => $id, 'counter' => 1)));
            $index = 1;
            $id = $this->ReceiptCounters->id;
        } else {
            $id = $rcpIndex['ReceiptCounters']['id'];
        }
        $this->ReceiptCounters->read(null, $id);
        $this->ReceiptCounters->set('counter', $index);
        $this->ReceiptCounters->save();
    }

    public function beforeFilter() {
        parent::beforeFilter();
        if (!$this->Session->check('Condo.ViewID')) {
            $this->Session->setFlash(__('Invalid fraction'), 'flash/error');
            $this->redirect(array('controller' => 'fractions', 'action' => 'index'));
        }
        
    }

    public function beforeRender() {
        $breadcrumbs = array(
            array('link' => Router::url(array('controller' => 'pages', 'action' => 'index')), 'text' => __('Home'), 'active' => ''),
            array('link' => Router::url(array('controller' => 'condos', 'action' => 'index')), 'text' => __n('Condo','Condos',2), 'active' => ''),
            array('link' => Router::url(array('controller' => 'condos', 'action' => 'view', $this->Session->read('Condo.ViewID'))), 'text' => $this->Session->read('Condo.ViewName'), 'active' => ''),
            array('link' => '', 'text' => __n('Receipt','Receipts',2), 'active' => 'active')
        );

        switch ($this->action) {
            case 'add_notes':
                $breadcrumbs[3] = array('link' => Router::url(array('controller' => 'receipts', 'action' => 'index')), 'text' => __n('Receipt','Receipts',2), 'active' => '');
                $breadcrumbs[4] = array('link' => Router::url(array('controller' => 'receipts', 'action' => 'view', $this->Session->read('Condo.Receipt.ViewID'))), 'text' => $this->Session->read('Condo.Receipt.ViewName'), 'active' => '');
                $breadcrumbs[5] = array('link' => '', 'text' => __('Pick   Notes'), 'active' => 'active');
                break;
            case 'view':
                $breadcrumbs[3] = array('link' => Router::url(array('controller' => 'receipts', 'action' => 'index')), 'text' => __n('Receipt','Receipts',2), 'active' => '');
                $breadcrumbs[4] = array('link' => '', 'text' => $this->Session->read('Condo.Receipt.ViewName'), 'active' => 'active');
                break;
            case 'edit':
                $breadcrumbs[3] = array('link' => Router::url(array('controller' => 'receipts', 'action' => 'index')), 'text' => __n('Receipt','Receipts',2), 'active' => '');
                $breadcrumbs[4] = array('link' => '', 'text' => $this->Session->read('Condo.Receipt.ViewName'), 'active' => 'active');
                break;
        }




        $this->set(compact('breadcrumbs'));
    }

}
