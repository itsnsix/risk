<template>
    <div class="main-outer">
        <div class="main-navbar">
            <span>BATORU</span>

            <div class="help-toggle" @click="toggleHelpModal">?</div>
            <div @click="toggleLabels" class="label-toggle">
                <img :class="{active: showLabels}" class="user-label" src="/images/default_avatar.png"/></div>
            <div class="navbar-toggle" @click="toggleEventMenu">
                <div></div>
                <div></div>
                <div></div>
            </div>
        </div>

        <div class="main">
            <div class="map-container dragscroll">
                <div class="territory-popover" v-if="popover"
                     :style="{'top': popover.y + 'px', 'left': popover.x + 'px'}">
                    <div class="popover-title" :style="{'background-color': popover.territory.occupation ? popover.territory.occupation.user.color : '#FFFFFF'}">
                        <img v-if="popover.territory.occupation" :src="popover.territory.occupation.user.image ? popover.territory.occupation.user.image : '/images/default_avatar.png'"/>
                        <span :style="popover.territory.occupation ? '' : 'color: #1D1F21; text-shadow: none;'">
                            {{popover.territory.occupation ? popover.territory.occupation.user.name : 'Unoccupied'}}
                        </span>
                        <div :style="popover.territory.occupation ? '' : 'color: #1D1F21; text-shadow: none;'" class="close-popover" @click="closePopover">X</div>
                    </div>
                    <div class="popover-content">
                        <u>Territory {{popover.territory.id}}</u>
                        <div v-if="popover.territory.occupation">Captured: <b>{{formatDate(popover.territory.occupation.api_created_at)}}</b></div>

                        <div v-if="popover.territory.occupation && popover.territory.occupation.previous_occupation">Previous occupant: <b
                                :style="{color: popover.territory.occupation.previous_occupation.user.color}">
                            {{popover.territory.occupation.previous_occupation.user.name}}</b>
                        </div>
                    </div>
                </div>

                <transition name="loading">
                    <div class="loading" v-if="status">{{status}}</div>
                </transition>

                <canvas @click="onMapClick" id="map" width="5644" height="2177"></canvas>

                <div class="user-labels" v-show="showLabels && !status">
                    <img class="user-label" :src="label.avatar" v-for="label in labels"
                         :id="'territory-' + label.territory.id"
                         :style="{top: label.y + 'px', left: label.x + 'px',
                            height: label.size + 'px', width: label.size + 'px'}">
                </div>
            </div>

            <transition name="slide-menu">
                <div class="sidebar" v-show="showMenu">
                    <div class="sidebar-header">Day {{stats ? stats.day : '-'}}</div>
                    <div class="stat-container" v-if="stats">
                        <div>
                            <vue-easy-pie-chart line-cap="butt" track-color="#282A2E" bar-color="#C5C8C6" :line-width="5"
                                                :percent="stats.occupied_percentage">
                                <p class="pie-percentage">{{stats.occupied_percentage}}%</p>
                                <p class="pie-subtitle">Conquered</p>
                            </vue-easy-pie-chart>
                        </div>
                        <div>
                            <table class="table">
                                <tbody>
                                <tr v-if="stats.highest_count">
                                    <td>Conqueror</td>
                                    <td><span><b :style="'color: ' + stats.highest_count.color">{{stats.highest_count.name}}</b></span></td>
                                </tr>
                                <tr v-if="stats.biggest">
                                    <td>Biggest</td>
                                    <td><span><b :style="'color: ' + stats.biggest.color">{{stats.biggest.name}}</b></span></td>
                                </tr>
                                <tr v-if="stats.angriest">
                                    <td>Angriest</td>
                                    <td><span><b :style="'color: ' + stats.angriest.color">{{stats.angriest.name}}</b></span></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-else>
                        <div class="warning-label">Loading stats...</div>
                    </div>

                    <div class="sidebar-header second-header">Event log</div>

                    <div class="events" v-if="events && events.data && events.data.length > 0">
                        <div class="event" v-for="event in events.data">
                            <div class="event-header">
                                <div class="timestamp">{{event.timestamp}}</div>
                                <div>
                                    <img v-if="event.extra && event.extra.territory_id"
                                         @click="scrollToTerritory(event.extra.territory_id)" src="/images/binoculars.png"/>
                                    <a v-if="event.extra && event.extra.source_url"
                                       :href="event.extra.source_url" target="_blank">
                                        <img src="/images/picture.png"/>
                                    </a>
                                </div>
                            </div>

                            <div class="event-text" v-html="event.text"></div>
                        </div>

                        <button v-if="events.next_page_url" @click="loadMoreEvents" class="btn" :disabled="loadingMoreEvents">
                            {{loadingMoreEvents ? 'Loading...' : 'Load more'}}
                        </button>
                    </div>
                    <div v-else-if="loadingEvents" class="warning-label">Loading events...</div>
                    <div class="warning-label" v-else>Whoops, seems nothing has happened here yet.</div>
                </div>
            </transition>

            <help-modal></help-modal>
        </div>
    </div>
</template>

<script>
    import Helpers from '../helpers';
    import VueEasyPieChart from 'vue-easy-pie-chart';
    import HelpModal from './HelpModal';

    export default {
        components:{
            VueEasyPieChart,
            HelpModal
        },

	    data() {
	    	return {
			    status: 'Loading map...',
			    territories: {},
                events: {data: []},
                canvas: null,
                context: null,
                labels: [],
                showMenu: false,
			    popover: null,
                showLabels: true,
                loadingMoreEvents: false,
                loadingEvents: true,
                loadingStats: true,
                stats: null
	    	}
	    },

	    mounted() {
            let labelWorker = new Worker('js/labler.js');
            labelWorker.addEventListener('message', e => {
                this.labels = e.data;
            }, false);
            let painterWorker = new Worker('js/painter.js');
            painterWorker.addEventListener('message', e => {
                this.context.putImageData(e.data, 0, 0);
                this.status = null;
            }, false);

	    	axios.get('/territories')
                .then(response => {
                    this.status = 'Drawing map...';
                    this.territories = response.data;

                    labelWorker.postMessage(this.territories);
                    this.setupMap(painterWorker);
                })
                .catch(response => {
                    this.status = 'Failed to load map.';
                });

            axios.get('/events')
                .then(response => {
                    this.events = response.data;
                    this.loadingEvents = false;
                })
                .catch(response => {
                    this.loadingEvents = false;
                });

            axios.get('/stats')
                .then(response => {
                    this.stats = response.data;
                    this.loadingStats = false;
                })
                .catch(response => {
                    this.loadingStats = false;
                });
        },

	    methods: {
            toggleHelpModal: function() {
                Bus.$emit('toggle-help-modal');
            },

            toggleLabels: function() {
                this.showLabels = !this.showLabels;
            },

	        scrollToTerritory: function(territoryID) {
                let turnOffLabels = !this.showLabels;
                this.showLabels = true; // Needs to be visible in order to scroll to them.
                setTimeout(() => {
                    let el = document.getElementById('territory-' + territoryID);
                    if (el) {
                        el.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'center'});

                        this.labels.forEach(l => {
                            if (l.territory.id === territoryID) {
                                this.popover = {
                                    territory: l.territory,
                                    x: l.x - 125,
                                    y: l.y + 15
                                };
                            }
                        });
                    } else {
                        console.log('No label for this territory.')
                    }

                    if (turnOffLabels) this.showLabels = false;
                });
            },

            loadMoreEvents: function() {
                if (!this.loadingMoreEvents) {
                    this.loadingMoreEvents = true;
                    axios.get(this.events.next_page_url)
                        .then(response => {
                            response.data.data = this.events.data.concat(response.data.data);
                            this.events = response.data;
                            this.loadingMoreEvents = false;
                        })
                        .catch(response => {
                            this.loadingMoreEvents = false;
                        });
                }
            },

		    formatDate: function(date) {
		    	return Helpers.formatDate(date);
            },

		    closePopover: function() {
		    	this.popover = null;
            },

		    toggleEventMenu: function() {
		    	this.showMenu = !this.showMenu;
            },

		    onMapClick: function(event) {
                // Show territory occupation if it exists.
			    let t = this.findTerritory({x: event.layerX, y: event.layerY});
			    if (t) {
				    this.popover = {
				    	territory: t,
                        x: event.layerX - 125, // Remove half the width of popover to center it.
                        y: event.layerY + 15
                    };
                } else {
			    	this.popover = null;
                }
            },

            findTerritory: function(location) {
                if (!this.context) {return null;}
	            let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);
	            if (!imageData) {return null;}

	            let color = Helpers.hexToRgb('#18424C'); // Reserved ocean color
	            let coloredPixels = 0;

	            let pixelStack = [[location.x, location.y]];

	            if (!Helpers.validateInitialPixel(imageData, this.canvas.width,
			            pixelStack[0][0], pixelStack[0][1])) {
		            return null;
	            }

	            while (pixelStack.length) {
		            // Where we current are in the image
		            let currentPosition = pixelStack.pop();
		            let x = currentPosition[0];
		            let y = currentPosition[1];
		            let pixelPosition = (y * this.canvas.width + x) * 4;
		            let matchingColor = {
			            r: imageData.data[pixelPosition],
			            g: imageData.data[pixelPosition + 1],
			            b: imageData.data[pixelPosition + 2]
		            };

		            // Walk upwards from position until we hit another color than the one we clicked on
		            while (y-- >= 0 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            pixelPosition -= this.canvas.width * 4;
		            }

		            pixelPosition += this.canvas.width * 4;
		            ++y;
		            let reachLeft = false, reachRight = false;
		            // Walk downwards and look for pixels of the same color to the left and right
		            while (y++ < this.canvas.height - 1 && Helpers.matchPixelColor(imageData, pixelPosition, matchingColor, color)) {
			            Helpers.colorPixel(imageData, pixelPosition, color);
			            coloredPixels++;

			            // Check if these coordinates is a territory
			            if (this.territories.hasOwnProperty(x + '-' + y)) {
			            	return this.territories[x + '-' + y];
                        }

			            // Check left
			            if (x > 0) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition - 4, matchingColor, color)) {
					            if (!reachLeft) {
						            pixelStack.push([x - 1, y]);
						            reachLeft = true;
					            }
				            } else if (reachLeft) {
					            reachLeft = false;
				            }
			            }

			            // Check right
			            if (x < this.canvas.width - 1) {
				            if (Helpers.matchPixelColor(imageData, pixelPosition + 4, matchingColor, color)) {
					            if (!reachRight) {
						            pixelStack.push([x + 1, y]);
						            reachRight = true;
					            }
				            } else if (reachRight) {
					            reachRight = false;
				            }
			            }

			            pixelPosition += this.canvas.width * 4;
		            }

		            if (coloredPixels > 50000) {
			            console.log('Too big area');
			            return null;
		            }
	            }

	            return null;
            },

		    setupMap: function(painter) {
			    this.canvas = document.getElementById('map');
			    this.context = this.canvas.getContext('2d');

			    let image = new Image();
			    image.src = '/images/map.png';
			    image.onload = () => {
				    this.context.drawImage(image, 0, 0);
                    let imageData = this.context.getImageData(0, 0, this.canvas.width, this.canvas.height);
				    painter.postMessage({
                        imageData: imageData,
                        territories: this.territories,
                        dimensions: {width: this.canvas.width, height: this.canvas.height}
				    });
			    };
		    }
	    }
    }
</script>
