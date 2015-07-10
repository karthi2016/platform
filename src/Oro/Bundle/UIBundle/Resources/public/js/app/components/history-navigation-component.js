/* global define */
/** @exports HistoryNavigationComponent */
define(function (require) {
    'use strict';

    var HistoryNavigationComponent,
        BaseComponent = require('../components/base/component'),
        StatefulModel = require('../models/base/stateful-model'),
        HistoryNavigationView = require('../views/history-navigation-view');

    /**
     * Builds history controls for undo/redo capability.
     *
     * @class HistoryNavigationComponent
     * @augments BaseComponent
     */
    HistoryNavigationComponent = BaseComponent.extend(/** @lends HistoryNavigationComponent.prototype */{
        history: null,
        observedModel: null,
        /**
         * @inheritDoc
         */
        initialize: function (options) {
            if (options.observedModel instanceof StatefulModel === false) {
                throw new Error('Observed object should be instance of StatefulModel');
            }
            this.observedModel = options.observedModel;
            this.historyView = new HistoryNavigationView({
                model: this.observedModel.history,
                el: options._sourceElement
            });
            this.historyView.on('navigate', this.onHistoryNavigate, this);
        },

        onHistoryNavigate: function (index) {
            var state,
                history = this.observedModel.history;
            if (history.setIndex(index)) {
                state = history.getCurrentState();
                this.observedModel.setState(state.get('data'));
            }
        }

    });

    return HistoryNavigationComponent;
});
